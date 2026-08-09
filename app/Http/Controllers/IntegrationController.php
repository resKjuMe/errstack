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
 * Die Anbindungen einer Organisation (X1).
 *
 * Die Seite beantwortet drei Fragen, und die Reihenfolge ist die, in der sie
 * aufkommen: Ist etwas verbunden? Trägt es noch? Welche Repositories versorgt
 * es?
 *
 * Die zweite ist der Grund, dass es diese Seite gibt und nicht nur einen Knopf
 * auf der Repository-Seite. Ein zurückgezogenes Token macht sich sonst nirgends
 * bemerkbar — die Commits einer Auslieferung kommen einfach nicht mehr, und das
 * sieht aus wie „diese Version hatte keine".
 */
class IntegrationController extends Controller
{
    public function index(Organization $organization): InertiaResponse
    {
        Gate::authorize('view', $organization);

        $integration = Integration::forOrganization($organization);

        return Inertia::render('integrations/Index', [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'canManage' => Gate::allows('manageIntegrations', $organization),
            // Ob diese Installation überhaupt eingerichtet ist. Ohne
            // Zugangsdaten der OAuth-App endet das Verbinden bei GitHub in
            // einer Fehlerseite — dann zeigt die Oberfläche lieber, was fehlt.
            'configured' => GitHubOAuth::isConfigured(),
            'provider' => [
                'value' => IntegrationProvider::GitHub->value,
                'label' => IntegrationProvider::GitHub->label(),
            ],
            'integration' => $integration === null ? null : self::payload($organization, $integration),
            'connectHref' => route('organizations.integrations.github.redirect', $organization),
            'repositoriesHref' => route('organizations.repositories.index', $organization),
            // Die Auswahlliste und das Verbinden hängen an derselben Adresse
            // (lesen bzw. schreiben) — sie kommt vom Server, wie jede andere
            // hier auch: eine im Browser zusammengebaute Adresse geht beim
            // nächsten Umbenennen einer Route still kaputt.
            'availableRepositoriesHref' => route('organizations.integrations.repositories.index', $organization),
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
     * Der Webhook drüben bleibt ebenfalls stehen. Ihn zu entfernen bräuchte
     * genau das Token, das hier gerade weggeworfen wird — und ein Aufruf, der
     * dabei scheitert, dürfte das Lösen nicht aufhalten. Was er künftig
     * schickt, wird abgewiesen; siehe {@see GitHubWebhook}.
     */
    public function destroy(Organization $organization, Integration $integration): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        abort_unless($integration->organization_id === $organization->id, 404);

        $integration->delete();

        return back()->with('status', __('integrations.flash.disconnected'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Organization $organization, Integration $integration): array
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
            'repositories' => $integration->repositories()
                ->orderBy('name')
                ->get()
                ->map(fn (Repository $repository): array => [
                    'id' => $repository->id,
                    'name' => $repository->name,
                    'url' => $repository->url,
                ])->all(),
        ];
    }
}
