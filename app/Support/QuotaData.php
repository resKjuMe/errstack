<?php

namespace App\Support;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Models\IngestDiscard;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\User;
use App\Support\Ingest\Quotas\QuotaCounter;
use App\Support\Ingest\Quotas\QuotaLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Kontingent-Seite — für ein Projekt wie für eine Organisation.
 *
 * Eine Klasse für beide Ebenen, weil es dieselbe Seite ist: Grenzen setzen,
 * Verbrauch ablesen, nachsehen, was verworfen wurde. Was sich unterscheidet,
 * ist der Gegenstand und eine Liste — die Schlüssel gibt es nur beim Projekt.
 *
 * **Der Verbrauch steht neben der Grenze, und die Verwerfungen darunter.** Das
 * ist die eigentliche Entscheidung an dieser Seite. Ein Kontingent ohne
 * sichtbaren Verbrauch ist eine Zahl, die man einmal einträgt und nie wieder
 * ansieht; eine gerissene Grenze ohne die Zählung des Verworfenen ist eine
 * stille Lücke in den Daten. Beides zusammen beantwortet die Frage, für die
 * jemand diese Seite überhaupt aufruft: „warum fehlen seit gestern Meldungen?"
 */
final class QuotaData
{
    /**
     * Über wie viele Tage die Verwerfungen gezählt werden.
     *
     * Dreißig wie bei den Eingangsfiltern, und aus demselben Grund: die Frage
     * „ist das viel?" ist an einem einzelnen Tag nicht zu beantworten.
     */
    public const WINDOW_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public static function forProject(Project $project, User $viewer): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        return self::payload(
            scope: QuotaScope::Project,
            scopeId: $project->id,
            organization: $organization,
            project: $project,
            mayManage: Gate::forUser($viewer)->allows('manageQuotas', $project),
            updateHref: route('projects.quotas.update', [$organization, $project]),
            projectIds: [$project->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOrganization(Organization $organization, User $viewer): array
    {
        return self::payload(
            scope: QuotaScope::Organization,
            scopeId: $organization->id,
            organization: $organization,
            project: null,
            mayManage: Gate::forUser($viewer)->allows('manageQuotas', $organization),
            updateHref: route('organizations.quotas.update', $organization),
            projectIds: $organization->projects()->pluck('id')->all(),
        );
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, mixed>
     */
    private static function payload(
        QuotaScope $scope,
        int $scopeId,
        Organization $organization,
        ?Project $project,
        bool $mayManage,
        string $updateHref,
        array $projectIds,
    ): array {
        $counter = new QuotaCounter;
        $limits = QuotaLimits::forScope($scope, $scopeId);

        return [
            'scope' => $scope->value,
            'scopeLabel' => $scope->label(),
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
                // Die Grenze der Organisation gilt über dem Projekt und ist auf
                // der Projektseite die halbe Antwort: wer dort ein großzügiges
                // Kontingent sieht und trotzdem abgewiesen wird, sucht sonst an
                // der falschen Stelle.
                'quotasHref' => route('organizations.quotas.index', $organization),
            ],
            'project' => $project === null ? null : [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
            ],
            'updateHref' => $updateHref,
            'periodLabel' => Carbon::now()->translatedFormat('F Y'),
            'windowDays' => self::WINDOW_DAYS,
            'categories' => self::categories($scope, $scopeId, $limits, $counter),
            'inherited' => $scope === QuotaScope::Project
                ? self::inherited($organization, $counter)
                : [],
            'keys' => $project === null ? [] : self::keys($project, $counter),
            'discards' => self::discards($projectIds),
            'permissions' => ['manage' => $mayManage],
        ];
    }

    /**
     * Alle Datenarten mit ihren Grenzen und dem Verbrauch dieses Monats.
     *
     * Öffentlich, weil es nicht nur die Kontingent-Seite gibt: die
     * Organisations-Übersicht (D5) zeigt denselben Verbrauch in einer Kachel
     * und braucht davon nur diesen Teil. Sie über die volle Seiten-Nutzlast zu
     * bedienen hieße, Schlüssel-Raten und Verwerfungen mitzurechnen und
     * wegzuwerfen — und eine zweite Rechnung daneben hieße, zwei Antworten auf
     * „wie viel ist noch übrig" zu haben.
     *
     * @param  array<string, array{id: int, month: int|null, minute: int|null}>|null  $limits
     * @return list<array<string, mixed>>
     */
    public static function categories(
        QuotaScope $scope,
        int $scopeId,
        ?array $limits = null,
        ?QuotaCounter $counter = null,
    ): array {
        $limits ??= QuotaLimits::forScope($scope, $scopeId);
        $counter ??= new QuotaCounter;

        return array_map(
            fn (QuotaCategory $category): array => self::category($category, $scope, $scopeId, $limits, $counter),
            QuotaCategory::cases(),
        );
    }

    /**
     * Eine Datenart mit ihrer Grenze und dem, was diesen Monat davon verbraucht
     * ist.
     *
     * @param  array<string, array{id: int, month: int|null, minute: int|null}>  $limits
     * @return array<string, mixed>
     */
    private static function category(
        QuotaCategory $category,
        QuotaScope $scope,
        int $scopeId,
        array $limits,
        QuotaCounter $counter,
    ): array {
        $limit = $limits[$category->value] ?? null;
        $perMonth = $limit === null ? null : $limit['month'];
        $perMinute = $limit === null ? null : $limit['minute'];
        $usage = $counter->monthUsage($scope, $scopeId, $category);

        return [
            'value' => $category->value,
            'label' => $category->label(),
            'hint' => __('quotas.categories.'.$category->value.'_hint'),
            'perMonth' => $perMonth,
            'perMinute' => $perMinute,
            'usage' => $usage,
            // Der Anteil nur dort, wo es eine Grenze gibt: ohne sie ist der
            // Verbrauch eine Zahl und kein Anteil von etwas.
            'percent' => $perMonth === null || $perMonth < 1
                ? null
                : min(999, (int) floor($usage / $perMonth * 100)),
            'usageLabel' => Formats::number($usage),
        ];
    }

    /**
     * Was von der Organisation her über dem Projekt liegt.
     *
     * Nur die Datenarten, für die dort überhaupt eine Grenze gesetzt ist —
     * fünf Zeilen „unbegrenzt" wären eine Tabelle, die nichts sagt.
     *
     * @return list<array<string, mixed>>
     */
    private static function inherited(Organization $organization, QuotaCounter $counter): array
    {
        $limits = QuotaLimits::forScope(QuotaScope::Organization, $organization->id);
        $rows = [];

        foreach (QuotaCategory::cases() as $category) {
            $limit = $limits[$category->value] ?? null;

            if ($limit === null) {
                continue;
            }

            $usage = $counter->monthUsage(QuotaScope::Organization, $organization->id, $category);

            $rows[] = [
                'value' => $category->value,
                'label' => $category->label(),
                'perMonth' => $limit['month'],
                'perMinute' => $limit['minute'],
                'usage' => $usage,
                'usageLabel' => Formats::number($usage),
            ];
        }

        return $rows;
    }

    /**
     * Die Schlüssel des Projekts mit ihrer eigenen Rate.
     *
     * Sie stehen hier zum Nachlesen und nicht zum Ändern — geändert werden sie
     * auf der Schlüssel-Seite, wo auch die DSN steht. Zwei Formulare für
     * dasselbe Feld wären zwei Stellen, an denen sich jemand fragt, welche
     * gerade gilt.
     *
     * @return list<array<string, mixed>>
     */
    private static function keys(Project $project, QuotaCounter $counter): array
    {
        return $project->keys()
            ->orderBy('name')
            ->get()
            ->map(fn (ProjectKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'active' => $key->active,
                'perMinute' => $key->rate_limit_per_minute,
                'usage' => $counter->minuteUsage(QuotaScope::Key, $key->id, null),
            ])
            ->values()
            ->all();
    }

    /**
     * Was in den letzten dreißig Tagen verworfen wurde, nach Grund.
     *
     * Beide Herkünfte nebeneinander: was wir abgewiesen haben, und was ein SDK
     * schon bei sich weggeworfen hat. Ohne die zweite Hälfte hätte die Seite
     * eine Erklärungslücke — ein SDK, das wegen unserer 429er in den Rückstau
     * läuft, verwirft danach selbst, und das taucht in unseren Gründen nicht auf.
     *
     * @param  list<int>  $projectIds
     * @return list<array<string, mixed>>
     */
    private static function discards(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return IngestDiscard::query()
            ->whereIn('project_id', $projectIds)
            ->where('bucket', '>=', Carbon::now()->subDays(self::WINDOW_DAYS)->startOfHour())
            ->selectRaw('origin, reason, sum(quantity) as total')
            ->groupBy('origin', 'reason')
            ->orderByDesc('total')
            ->get()
            ->map(fn (IngestDiscard $row): array => [
                'origin' => $row->origin->value,
                'originLabel' => $row->origin->label(),
                'reason' => $row->reason,
                // Die eigenen Gründe tragen eine Bezeichnung, die des SDK nicht:
                // dessen Liste wächst mit jeder Fassung, und ein unübersetzter
                // Rohwert ist die ehrlichere Auskunft als eine erfundene.
                'reasonLabel' => DiscardReason::tryFrom($row->reason)?->label() ?? $row->reason,
                'quantity' => (int) $row->getAttribute('total'),
                'quantityLabel' => Formats::number((int) $row->getAttribute('total')),
                'fromClient' => $row->origin === DiscardOrigin::Client,
            ])
            ->values()
            ->all();
    }
}
