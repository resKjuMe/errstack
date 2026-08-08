<?php

namespace App\Support;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\InboundFilterKind;
use App\Models\InboundFilterRule;
use App\Models\IngestDiscard;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Ingest\Filtering\Browsers;
use App\Support\Ingest\Filtering\Defaults;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Filterseite eines Projekts: die Schalter, die Listen und die
 * Zählung dessen, was sie weggenommen haben.
 *
 * Die Zählung steht **auf derselben Seite** wie die Schalter, und das ist die
 * wichtigste Entscheidung an dieser Klasse. Ein Filter ohne sichtbare Wirkung
 * ist eine Behauptung: man schaltet ihn ein und weiß danach nicht, ob er zwei
 * Meldungen im Monat trifft oder die Hälfte des Aufkommens. Wer die Zahl neben
 * dem Häkchen sieht, merkt sofort, wenn er zu viel weggenommen hat — und das
 * ist der einzige Weg, den Fehler überhaupt zu bemerken, denn eine gefilterte
 * Meldung hinterlässt in der Fehlerliste keine Lücke.
 */
final class InboundFilterData
{
    /**
     * Über welchen Zeitraum die Zählung läuft, in Tagen.
     *
     * Dreißig, weil die Frage „nimmt der Filter zu viel weg?" an einem einzelnen
     * Tag nicht zu beantworten ist: eine Anwendung, die wöchentlich ausgeliefert
     * wird, hat an manchen Tagen kein Rauschen und an anderen nichts als das.
     */
    public const WINDOW_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public static function forProject(Project $project, User $viewer): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $mayManage = Gate::forUser($viewer)->allows('manageFilters', $project);
        $counts = self::counts($project);

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'optionsHref' => route('projects.filters.update', [$organization, $project]),
                'rulesHref' => route('projects.filters.rules.store', [$organization, $project]),
            ],
            'kinds' => array_map(
                fn (InboundFilterKind $kind): array => self::kind($kind, $project, $counts),
                InboundFilterKind::cases(),
            ),
            'rules' => self::rules($organization, $project),
            'ruleOptions' => InboundFilterKind::ruleOptions(),
            'browserDefaults' => Browsers::defaults(),
            'knownHosts' => Defaults::LOCAL_HOSTS,
            'windowDays' => self::WINDOW_DAYS,
            'filtered' => array_sum($counts),
            'permissions' => ['manage' => $mayManage],
            'maxPerKind' => InboundFilterRule::MAX_PER_KIND,
        ];
    }

    /**
     * Eine Filterart samt ihrem Schalter und ihrer Zählung.
     *
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private static function kind(InboundFilterKind $kind, Project $project, array $counts): array
    {
        return [
            'value' => $kind->value,
            'column' => $kind->column(),
            'label' => $kind->label(),
            'hint' => __('inbound.kinds.'.$kind->value.'_hint'),
            'enabled' => (bool) $project->getAttribute($kind->column()),
            'usesRules' => $kind->usesRules(),
            'filtered' => $counts[$kind->value] ?? 0,
        ];
    }

    /**
     * Wie viele Ereignisse je Filterart weggefiltert wurden.
     *
     * Gelesen wird die Verwerfungs-Zählung ({@see IngestDiscard}) und nicht eine
     * eigene Tabelle: dort steht ohnehin schon, was die eigene Seite verworfen
     * hat, und ein zweiter Zähler daneben wäre einer, der irgendwann eine andere
     * Zahl zeigt als der erste.
     *
     * @return array<string, int>
     */
    private static function counts(Project $project): array
    {
        /** @var array<string, int> $counts */
        $counts = IngestDiscard::query()
            ->where('project_id', $project->id)
            ->where('origin', DiscardOrigin::Server->value)
            ->where('reason', DiscardReason::Filtered->value)
            ->where('bucket', '>=', Carbon::now()->subDays(self::WINDOW_DAYS)->startOfHour())
            ->whereNotNull('category')
            ->selectRaw('category, sum(quantity) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return $counts;
    }

    /**
     * Die Einträge des Projekts, nach Filterart gebündelt.
     *
     * Gebündelt und nicht als eine Liste: die vier Arten haben nichts
     * miteinander zu tun, und eine gemeinsame Liste zwänge den Betrachter, die
     * Art jeder Zeile zu lesen, um zu wissen, worauf sie wirkt.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function rules(Organization $organization, Project $project): array
    {
        $grouped = [];

        foreach (InboundFilterKind::cases() as $kind) {
            if ($kind->usesRules()) {
                $grouped[$kind->value] = [];
            }
        }

        $rules = $project->inboundFilterRules()->orderBy('id')->get();

        foreach ($rules as $rule) {
            $grouped[$rule->kind->value][] = [
                'id' => $rule->id,
                'kind' => $rule->kind->value,
                'expression' => $rule->expression,
                'isActive' => $rule->is_active,
                // Ändern und Löschen über dieselbe Adresse — eine Angabe statt
                // zweier, die auseinanderlaufen können.
                'href' => route('projects.filters.rules.update', [$organization, $project, $rule]),
                'toggleHref' => route('projects.filters.rules.toggle', [$organization, $project, $rule]),
            ];
        }

        return $grouped;
    }
}
