<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Repository;
use App\Support\Formats;
use App\Support\Integrations\GitHub\GitHubOAuth;
use App\Support\Integrations\GitHub\GitHubWebhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Anbindungen einer Organisation (X1, X4).
 *
 * Die Seite beantwortet drei Fragen, und die Reihenfolge ist die, in der sie
 * aufkommen: Ist etwas verbunden? Trägt es noch? Welche Repositories bzw.
 * Projekte versorgt es?
 *
 * Die zweite ist der Grund, dass es diese Seite gibt und nicht nur einen Knopf
 * auf der Repository-Seite. Ein zurückgezogenes Token macht sich sonst nirgends
 * bemerkbar — die Commits einer Auslieferung kommen einfach nicht mehr, und das
 * sieht aus wie „diese Version hatte keine".
 *
 * **Ein Abschnitt je Anbieter, alle auf einer Seite** (X4). Die naheliegende
 * Alternative wäre eine Seite je Anbieter, und sie wäre schlechter: die Frage
 * „was ist hier eigentlich angebunden?" ist eine Frage über alle, und wer sie
 * beantworten will, soll nicht drei Seiten aufsuchen müssen. Der Preis ist eine
 * Seite, die mit jedem Anbieter länger wird — was tragbar bleibt, weil ein nicht
 * verbundener Anbieter nur eine Zeile und einen Knopf trägt.
 */
class IntegrationController extends Controller
{
    public function index(Organization $organization): InertiaResponse
    {
        Gate::authorize('view', $organization);

        return Inertia::render('integrations/Index', [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'canManage' => Gate::allows('manageIntegrations', $organization),
            'github' => self::github($organization),
            'tickets' => self::tickets($organization),
            'repositoriesHref' => route('organizations.repositories.index', $organization),
        ]);
    }

    /**
     * Die Anbindung lösen.
     *
     * Die Repositories bleiben — samt ihrer Commits und damit samt dem Inhalt
     * jeder Auslieferung, die aus ihnen bestand. Sie fallen auf den Stand
     * zurück, den sie ohne Anbindung hätten: eingetragen, mit ihrer Geschichte,
     * nur ohne jemanden, der Neues holt. Das ist die Zusage, wegen der
     * `integration_id` mit `nullOnDelete` hängt und nicht mit `cascade`.
     *
     * Dasselbe gilt für verknüpfte Tickets (X4): sie bleiben lesbar und werden
     * nicht mehr abgeglichen. Die Verknüpfung trägt Kennung und Adresse bei sich.
     *
     * Der Webhook drüben bleibt ebenfalls stehen. Ihn zu entfernen bräuchte
     * genau das Token, das hier gerade weggeworfen wird — und ein Aufruf, der
     * dabei scheitert, dürfte das Lösen nicht aufhalten. Was er künftig
     * schickt, wird abgewiesen; siehe {@see GitHubWebhook} und, für die
     * Ticket-Systeme, das Geheimnis in der Rückadresse, das mit der Zeile
     * verschwindet.
     */
    public function destroy(Organization $organization, Integration $integration): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        abort_unless($integration->organization_id === $organization->id, 404);

        $integration->delete();

        return back()->with('status', __('integrations.flash.disconnected'));
    }

    /**
     * Der Abschnitt für GitHub — mit allem, was nur dort gilt: die Anmeldung über
     * OAuth (und damit die Frage, ob diese Installation dafür eingerichtet ist)
     * und die Repository-Auswahl.
     *
     * @return array<string, mixed>
     */
    private static function github(Organization $organization): array
    {
        $integration = Integration::forOrganization($organization, IntegrationProvider::GitHub);

        return [
            'provider' => [
                'value' => IntegrationProvider::GitHub->value,
                'label' => IntegrationProvider::GitHub->label(),
            ],
            // Ob diese Installation überhaupt eingerichtet ist. Ohne
            // Zugangsdaten der OAuth-App endet das Verbinden bei GitHub in
            // einer Fehlerseite — dann zeigt die Oberfläche lieber, was fehlt.
            'configured' => GitHubOAuth::isConfigured(),
            'integration' => $integration === null ? null : [
                ...self::common($organization, $integration),
                'repositories' => $integration->repositories()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Repository $repository): array => [
                        'id' => $repository->id,
                        'name' => $repository->name,
                        'url' => $repository->url,
                    ])->all(),
            ],
            'connectHref' => route('organizations.integrations.github.redirect', $organization),
            // Die Auswahlliste und das Verbinden hängen an derselben Adresse
            // (lesen bzw. schreiben) — sie kommt vom Server, wie jede andere
            // hier auch: eine im Browser zusammengebaute Adresse geht beim
            // nächsten Umbenennen einer Route still kaputt.
            'availableRepositoriesHref' => route('organizations.integrations.repositories.index', $organization),
        ];
    }

    /**
     * Die Abschnitte der Ticket-Systeme.
     *
     * Einer je Anbieter, auch für die nicht verbundenen: dort steht dann das
     * Formular zum Verbinden. Das ist der Unterschied zur Fehlerseite, wo nur
     * das Verbundene auftaucht — hier ist „was könnte ich anbinden?" die Frage,
     * mit der jemand die Seite öffnet.
     *
     * @return list<array<string, mixed>>
     */
    private static function tickets(Organization $organization): array
    {
        $sections = [];

        foreach (IntegrationProvider::ticketProviders() as $provider) {
            $integration = Integration::forOrganization($organization, $provider);

            $sections[] = [
                'provider' => ['value' => $provider->value, 'label' => $provider->label()],
                // Jira braucht die Adresse der Instanz und die E-Mail-Adresse
                // zum Token, Linear nur den Schlüssel. Welche Felder das
                // Formular zeigt, entscheidet der Server — sonst steht die Liste
                // zweimal da, hier und in der Ansicht.
                'fields' => $provider === IntegrationProvider::Jira
                    ? ['base_url', 'email', 'token']
                    : ['token'],
                'connectHref' => route('organizations.integrations.tickets.store', [$organization, $provider->value]),
                'docsUrl' => (string) config('services.'.$provider->value.'.docs_url', ''),
                'integration' => $integration === null ? null : [
                    ...self::common($organization, $integration),
                    'syncInbound' => $integration->syncsInbound(),
                    'syncOutbound' => $integration->syncsOutbound(),
                    'defaultProject' => $integration->setting('default_project'),
                    'defaultType' => $integration->setting('default_type'),
                    'defaultPriority' => $integration->setting('default_priority'),
                    'defaultAssignee' => $integration->setting('default_assignee'),
                    // Die vollständige Rückadresse, zum Eintragen drüben. Sie
                    // enthält das Geheimnis — deshalb sieht sie nur, wer die
                    // Anbindungen verwalten darf (die Ansicht zeigt sie hinter
                    // `canManage`), und deshalb gibt es den Weg, sie zu
                    // erneuern.
                    'webhookUrl' => $integration->webhookToken() === null ? null : route('webhooks.tickets', [
                        'provider' => $integration->provider->value,
                        'token' => $integration->webhookToken(),
                    ]),
                    'settingsHref' => route('organizations.integrations.tickets.update', [$organization, $integration]),
                    'rotateHref' => route('organizations.integrations.tickets.rotate', [$organization, $integration]),
                    'targetsHref' => route('organizations.integrations.tickets.targets', [$organization, $integration->provider->value]),
                ],
            ];
        }

        return $sections;
    }

    /**
     * Was jede Anbindung über sich sagt — unabhängig vom Anbieter.
     *
     * @return array<string, mixed>
     */
    private static function common(Organization $organization, Integration $integration): array
    {
        return [
            'id' => $integration->id,
            'account' => $integration->account,
            'disconnectHref' => route('organizations.integrations.destroy', [$organization, $integration]),
            'status' => $integration->status->value,
            'statusLabel' => $integration->status->label(),
            'connectedAt' => $integration->created_at?->toIso8601String(),
            'connectedAtLabel' => $integration->created_at === null
                ? null
                : Formats::dateTime($integration->created_at),
            'connectedBy' => $integration->connectedBy?->name,
            'lastSyncedAtLabel' => $integration->last_synced_at === null
                ? null
                : Formats::dateTime($integration->last_synced_at),
            // Die Meldung des Anbieters, wörtlich. Sie steht hier, weil „Bad
            // credentials" die Antwort auf „woran liegt es?" ist und ein
            // eigener, freundlicherer Satz sie nur verstecken würde.
            'lastError' => $integration->last_error,
        ];
    }
}
