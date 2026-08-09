<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Repository;
use App\Support\Integrations\GitHub\GitHubClient;
use App\Support\Integrations\GitHub\GitHubException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Die Repository-Auswahl einer Anbindung (X1).
 *
 * Zwei Adressen: eine, die auflistet, was drüben erreichbar ist, und eine, die
 * daraus eine Auswahl macht. Die Liste wird **auf Anforderung** geholt und
 * nicht mit der Seite mitgeliefert — sie ist ein Aufruf über das Netz, und die
 * Anbindungsseite soll auch dann laden, wenn GitHub gerade nicht antwortet.
 *
 * **Auswählen heißt hier: dasselbe wie von Hand eintragen, nur mit Herkunft.**
 * Es entsteht dieselbe Zeile in `repositories` wie bei R2 — mit `provider` und
 * `external_id` gefüllt und einem Verweis auf die Anbindung. Ein zweiter Ort
 * für „verbundene Repositories" wäre die naheliegende Alternative und die
 * falsche: jede Abfrage, die Commits einem Repository zuordnet, müsste dann
 * beide kennen.
 */
class IntegrationRepositoryController extends Controller
{
    /**
     * Was drüben zur Auswahl steht.
     *
     * Antwortet als JSON und nicht als Inertia-Seite: die Liste erscheint in
     * einem Auswahlfeld auf der Anbindungsseite, und ein Seitenwechsel dafür
     * wäre ein Umweg über den Server, der nichts hinzufügt.
     */
    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        $integration = Integration::forOrganization($organization);

        if ($integration === null || ! $integration->isUsable()) {
            return response()->json([
                'repositories' => [],
                'error' => __('integrations.errors.not_connected'),
            ], 409);
        }

        try {
            $repositories = (new GitHubClient($integration))->repositories();
        } catch (GitHubException $e) {
            // Der Fehler steht in der Antwort und nicht in einer Ausnahme: das
            // Auswahlfeld soll sagen, warum es leer ist. „Verbindung verloren"
            // ist an der Anbindung bereits festgehalten (siehe GitHubClient) —
            // die Seite zeigt es beim nächsten Laden.
            return response()->json([
                'repositories' => [],
                'error' => $e->getMessage(),
            ], 502);
        }

        $connected = $organization->repositories()->pluck('name')->all();

        return response()->json([
            'repositories' => array_values(array_filter(
                $repositories,
                fn (array $repository): bool => ! in_array($repository['name'], $connected, true),
            )),
        ]);
    }

    /**
     * Ein Repository verbinden.
     *
     * Wiederholbar: ein Repository, das schon eingetragen ist — von Hand oder
     * weil eine Bauumgebung seinen Namen einmal mitgeschickt hat —, wird der
     * Anbindung zugeordnet, statt als Dublette abgewiesen zu werden. Das ist
     * der Regelfall bei einer Organisation, die vorher ohne Anbindung
     * gearbeitet hat, und ihr Repository samt Commit-Geschichte deshalb
     * wegzuwerfen wäre absurd.
     */
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        $integration = Integration::forOrganization($organization);

        if ($integration === null || ! $integration->isUsable()) {
            return back()->with('status', __('integrations.errors.not_connected'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.Repository::NAME_LIMIT],
            'external_id' => ['nullable', 'string', 'max:200'],
            'url' => ['nullable', 'string', 'url', 'max:500'],
        ], [], [
            'name' => __('repositories.fields.name'),
        ]);

        $name = Repository::normalizeName($validated['name']);

        if ($name === null) {
            return back()->with('status', __('integrations.errors.invalid_repository'));
        }

        $repository = Repository::forName($organization, $name, $validated['url'] ?? null);

        $repository->forceFill([
            'integration_id' => $integration->id,
            'provider' => IntegrationProvider::GitHub->value,
            'external_id' => $validated['external_id'] ?? $repository->external_id,
        ])->save();

        $this->registerWebhook($integration, $name);

        return back()->with('status', __('integrations.flash.repository_connected', ['name' => $name]));
    }

    /**
     * Den Webhook einrichten — und ein Scheitern nicht zum Scheitern des
     * Verbindens machen.
     *
     * Ohne Hook fehlt der Zustandsabgleich; Commits holen und Tickets anlegen
     * gehen weiter. Das Verbinden daran scheitern zu lassen hieße, eine
     * Anbindung wegen ihres kleinsten Teils zu verweigern — und der scheitert
     * regelmäßig aus einem Grund, den niemand hier ändern kann: wer nur
     * Schreibrecht auf den Code hat, darf am Repository keine Hooks einrichten.
     */
    private function registerWebhook(Integration $integration, string $repository): void
    {
        $secret = trim((string) config('services.github.webhook_secret'));

        if ($secret === '') {
            // Ohne Geheimnis würde jede eingehende Meldung ohnehin abgewiesen
            // (siehe GitHubWebhook::verify()). Einen Hook einzurichten, dessen
            // Meldungen sicher im Nichts landen, wäre irreführend.
            return;
        }

        try {
            (new GitHubClient($integration))->ensureWebhook(
                $repository,
                route('webhooks.github'),
                $secret,
            );
        } catch (GitHubException $e) {
            Log::info('Webhook für ein Repository nicht eingerichtet.', [
                'repository' => $repository,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
