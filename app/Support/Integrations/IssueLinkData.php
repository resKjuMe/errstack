<?php

namespace App\Support\Integrations;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Models\Repository;
use Illuminate\Support\Facades\Gate;

/**
 * Was die Fehlerseite über verknüpfte Tickets wissen muss (X1, X4).
 *
 * **Ohne Anbindung kommt `null` heraus** und nicht ein leerer Bereich mit einem
 * Formular, das nirgends hinführt. Das ist dieselbe Regel wie bei den Anhängen
 * und den verdächtigen Commits: die Seite zeigt einen Bereich erst, wenn es
 * etwas darin gibt — sonst wächst sie mit jedem Fachgebiet um einen leeren
 * Kasten, den niemand je füllen wird.
 *
 * Die eine Ausnahme: **verknüpfte Tickets stehen auch dann da, wenn die
 * Anbindung gelöst wurde.** Die Verknüpfung trägt Adresse und Nummer bei sich
 * und bleibt lesbar — sie wird nur nicht mehr abgeglichen. Sie verschwinden zu
 * lassen hieße, eine Aussage über den Fehler wegen einer Einstellung an anderer
 * Stelle zu verbergen.
 *
 * **Mehrere Anbieter nebeneinander** (X4), und die Liste der auswählbaren
 * Anbieter ist genau die der benutzbaren Anbindungen: wer GitHub und Jira
 * verbunden hat, sieht zwei Einträge; wer nur Linear hat, sieht keine Auswahl,
 * sondern Linear. Ein Anbieter, der nicht verbunden ist, steht nicht als
 * ausgegraute Möglichkeit da — das wäre Werbung an der falschen Stelle.
 *
 * **Wohin ein Ticket gelegt werden kann, steht hier nicht drin** — außer bei
 * GitHub, wo die Repositories als Zeilen in der Datenbank liegen. Die Projekte
 * eines Ticket-Systems sind ein Aufruf über das Netz, und die Fehlerseite soll
 * sich nicht deshalb um eine Netzwerkrunde verzögern, weil rechts ein Formular
 * steht, das niemand benutzt. Sie werden über `targetsHref` geholt, wenn jemand
 * das Formular öffnet.
 */
final class IssueLinkData
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forIssue(Issue $issue): ?array
    {
        $organization = $issue->project?->organization;

        if ($organization === null) {
            return null;
        }

        $links = $issue->links()->orderBy('id')->get();
        $providers = self::providers($organization);

        if ($providers === [] && $links->isEmpty()) {
            return null;
        }

        return [
            // Ob sich etwas anlegen oder verknüpfen lässt. Getrennt von der
            // Liste, weil beides unabhängig voneinander leer bzw. falsch sein
            // kann: verknüpfte Tickets ohne Anbindung, Anbindung ohne Tickets.
            'canLink' => $providers !== [] && Gate::allows('update', $issue),
            'storeHref' => route('issues.links.store', $issue),
            'providers' => $providers,
            'links' => $links->map(fn (IssueLink $link): array => [
                'id' => $link->id,
                'provider' => $link->provider->value,
                'providerLabel' => $link->provider->label(),
                'reference' => $link->reference(),
                'title' => $link->title,
                'url' => $link->url,
                'state' => $link->state->value,
                'stateLabel' => $link->state->label(),
                'deleteHref' => route('issues.links.destroy', [$link->issue_id, $link->id]),
            ])->all(),
        ];
    }

    /**
     * Die Anbieter, in denen sich für diese Organisation ein Ticket anlegen
     * lässt.
     *
     * Je Anbieter steht dabei, **wie** das Ziel ausgewählt wird: GitHub bringt
     * seine Repositories mit (sie liegen als Zeilen hier), die Ticket-Systeme
     * eine Adresse, hinter der die Liste auf Anforderung geholt wird. Der
     * Unterschied gehört in die Daten und nicht in die Ansicht — sonst steht dort
     * ein `if (provider === 'github')`, und der vierte Anbieter braucht ein
     * weiteres.
     *
     * @return list<array<string, mixed>>
     */
    private static function providers(Organization $organization): array
    {
        // **Eine** Abfrage für alle Anbieter, nicht eine je Anbieter: das steht
        // auf jeder Fehlerseite, und drei Abfragen für „welche gibt es?" wären
        // drei mehr auf einer Seite, die ohnehin viele hat.
        $integrations = Integration::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy(fn (Integration $integration): string => $integration->provider->value);

        $providers = [];

        foreach (IntegrationProvider::cases() as $provider) {
            $integration = $integrations->get($provider->value);

            if ($integration === null || ! $integration->isUsable()) {
                continue;
            }

            $targets = $provider->hasRepositories() ? self::repositories($organization) : [];

            // Ein GitHub-Zugang ohne ausgewähltes Repository ist keine
            // Möglichkeit, sondern eine halbe Einrichtung: es gibt nichts, worin
            // ein Ticket entstehen könnte. Bei den Ticket-Systemen ist das
            // anders — dort liegt die Auswahl drüben, und ob sie leer ist, weiß
            // man erst nach einem Aufruf.
            if ($provider->hasRepositories() && $targets === []) {
                continue;
            }

            $providers[] = [
                'value' => $provider->value,
                'label' => $provider->label(),
                'targets' => $targets,
                'targetsHref' => $provider->isTicketProvider()
                    ? route('organizations.integrations.tickets.targets', [$organization, $provider->value])
                    : null,
                // Die Vorbelegung der Anbindung — das Projekt, in dem Tickets
                // dieser Organisation entstehen. Sie steht hier, damit das
                // Formular ausgefüllt ist, bevor irgendetwas geholt wurde: der
                // Regelfall ist ein Projekt, und dann soll „Ticket anlegen" ein
                // Klick sein und keine Auswahl.
                'defaultTarget' => $integration->setting('default_project'),
            ];
        }

        return $providers;
    }

    /**
     * Die Repositories, die über eine Anbindung hereingekommen sind.
     *
     * @return list<string>
     */
    private static function repositories(Organization $organization): array
    {
        return $organization->repositories()
            ->whereNotNull('integration_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Repository $repository): string => $repository->name)
            ->all();
    }
}
