<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Organization;
use App\Support\Integrations\Tickets\Jira\JiraTicketProvider;
use App\Support\Integrations\Tickets\Linear\LinearTicketProvider;
use App\Support\Integrations\Tickets\TicketException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ein Ticket-System verbinden und einstellen (X4).
 *
 * **Verbunden wird über ein Formular und nicht über eine Weiterleitung.** Das ist
 * der auffälligste Unterschied zu GitHub (X1), und er ist keine Abkürzung: eine
 * OAuth-Anmeldung setzt eine App voraus, die im Marketplace von Atlassian bzw.
 * bei Linear registriert und je Installation eingerichtet ist. Bis dahin gibt es
 * einen Weg, der sofort funktioniert — ein API-Token, das sich jeder in seinen
 * Kontoeinstellungen erzeugt. Der Preis ist ein Formular mit drei Feldern statt
 * einem Knopf, und die Zusage bleibt dieselbe: das Token verlässt die
 * verschlüsselte Ablage nie wieder.
 *
 * **Das Token wird geprüft, bevor es gespeichert wird.** Der erste Aufruf beim
 * Anbieter entscheidet, ob überhaupt eine Anbindung entsteht — eine Zeile mit
 * einem falschen Token wäre eine Anbindung, die in der Oberfläche „verbunden"
 * heißt und bei jedem Aufruf scheitert. Der Kontoname aus derselben Antwort ist
 * das, was die Seite danach anzeigt: „verbunden mit Christian Mietze" beantwortet
 * die Frage, wessen Rechte hier gelten.
 */
class TicketIntegrationController extends Controller
{
    /**
     * Verbinden — oder ein vorhandenes Token ersetzen.
     */
    public function store(Request $request, Organization $organization, string $provider): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        $case = self::provider($provider);

        $validated = $request->validate(
            self::rules($case),
            [],
            self::attributes(),
        );

        // Geprüft wird mit einer **ungespeicherten** Anbindung: der Client liest
        // seine Zugangsdaten aus ihr, und sie darf nicht in der Datenbank
        // stehen, bevor der Anbieter geantwortet hat. Genau daran hängt die
        // Zusage, dass ein Tippfehler beim Erneuern die funktionierende
        // Anbindung nicht ersetzt.
        $candidate = new Integration;
        $candidate->organization_id = $organization->id;
        $candidate->provider = $case;
        $candidate->forceFill([
            'credentials' => self::credentials($case, $validated),
            'status' => IntegrationStatus::Connected,
        ]);

        try {
            $viewer = match ($case) {
                IntegrationProvider::Jira => (new JiraTicketProvider($candidate))->verify(),
                IntegrationProvider::Linear => (new LinearTicketProvider($candidate))->verify(),
                default => throw TicketException::failed(__('integrations.errors.unsupported_provider')),
            };
        } catch (TicketException $e) {
            // Als Prüffehler am Formular und nicht als Fehlerseite: ein
            // abgelehntes Token ist eine Eingabe, die man korrigiert, und die
            // Meldung des Anbieters gehört an das Feld, in dem sie steht.
            throw ValidationException::withMessages([
                'token' => $e->getMessage(),
            ]);
        }

        // Die vorhandene Anbindung wird **weiterverwendet** und nicht ersetzt: es
        // gibt eine je Organisation und Anbieter, und eine neue Zeile ginge am
        // eindeutigen Index nicht vorbei. Wichtiger noch — die alte zu löschen
        // nähme allen verknüpften Tickets ihre Zuordnung, und niemand würde sie
        // mehr abgleichen.
        $integration = Integration::forOrganization($organization, $case) ?? new Integration;

        $integration->organization_id = $organization->id;
        $integration->provider = $case;

        // Das Geheimnis der Rückadresse bleibt beim Ersetzen eines Tokens
        // stehen: es beim Erneuern des Zugangs mitzuwechseln hieße, dass die
        // Adresse drüben stillschweigend ungültig wird — und der Abgleich
        // aufhört, ohne dass jemand etwas geändert hätte, was damit zu tun hat.
        // Erneuert wird sie eigens (siehe rotate()).
        $webhookToken = $integration->webhookToken() ?? Str::random(48);

        // `forceFill` für die Zugangsdaten: sie stehen mit Absicht nicht in
        // `fillable` (siehe Integration), damit sie nie aus einer Anfrage heraus
        // gesetzt werden können — ein Massen-Zuweisen ist genau der Weg, auf dem
        // ein Token versehentlich hineingerät.
        $integration->forceFill([
            'account' => Str::limit($viewer['account'], 200, ''),
            'external_id' => Str::limit($viewer['external_id'], 200, ''),
            'credentials' => [...self::credentials($case, $validated), 'webhook_token' => $webhookToken],
            'webhook_token_hash' => Integration::hashWebhookToken($webhookToken),
            'status' => IntegrationStatus::Connected,
            'last_error' => null,
            'last_error_at' => null,
            'connected_by_id' => $request->user()?->id,
        ])->save();

        return back()->with('status', __('integrations.flash.ticket_connected', [
            'provider' => $case->label(),
            'account' => $integration->account,
        ]));
    }

    /**
     * Die Einstellungen ändern: die beiden Schalter des Abgleichs und die
     * Vorbelegung für neue Tickets.
     *
     * Ein Formular für beides, weil es dieselbe Frage aus zwei Richtungen ist —
     * „was passiert automatisch?" und „womit fängt ein neues Ticket an?".
     */
    public function update(Request $request, Organization $organization, Integration $integration): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        abort_unless($integration->organization_id === $organization->id, 404);
        abort_unless($integration->provider->isTicketProvider(), 404);

        $validated = $request->validate([
            'sync_inbound' => ['required', 'boolean'],
            'sync_outbound' => ['required', 'boolean'],
            // Die Vorbelegung wird **nicht** gegen den Anbieter geprüft. Ein
            // Aufruf dafür wäre eine Netzwerkrunde beim Speichern einer
            // Einstellung — und ein Projektschlüssel, den es nicht gibt, meldet
            // sich beim ersten Anlegen mit der Meldung des Anbieters. Die ist
            // aussagekräftiger als alles, was hier zu prüfen wäre.
            'default_project' => ['nullable', 'string', 'max:100'],
            'default_type' => ['nullable', 'string', 'max:100'],
            'default_priority' => ['nullable', 'string', 'max:100'],
            'default_assignee' => ['nullable', 'string', 'max:200'],
        ], [], self::attributes());

        $integration->forceFill([
            'settings' => [
                'sync_inbound' => (bool) $validated['sync_inbound'],
                'sync_outbound' => (bool) $validated['sync_outbound'],
                ...array_filter([
                    'default_project' => $validated['default_project'] ?? null,
                    'default_type' => $validated['default_type'] ?? null,
                    'default_priority' => $validated['default_priority'] ?? null,
                    'default_assignee' => $validated['default_assignee'] ?? null,
                ], fn (?string $value): bool => $value !== null && trim($value) !== ''),
            ],
        ])->save();

        return back()->with('status', __('integrations.flash.settings_saved'));
    }

    /**
     * Das Geheimnis der Rückadresse erneuern.
     *
     * Es gibt keinen Weg, es zu widerrufen, außer es zu ersetzen — und den
     * braucht es: eine Adresse, die einmal in einem Ticket, einem Chat oder einem
     * Zustellungsprotokoll gestanden hat, ist kein Geheimnis mehr. Die alte
     * Adresse antwortet danach mit `401`, und wer sie drüben eingetragen hat, muss
     * sie ersetzen. Deshalb steht das nicht neben dem Speichern-Knopf, sondern
     * als eigene Handlung mit einer Rückfrage.
     */
    public function rotate(Organization $organization, Integration $integration): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $organization);

        abort_unless($integration->organization_id === $organization->id, 404);
        abort_unless($integration->provider->isTicketProvider(), 404);

        $token = Str::random(48);

        $integration->forceFill([
            'credentials' => [...($integration->credentials ?? []), 'webhook_token' => $token],
            'webhook_token_hash' => Integration::hashWebhookToken($token),
        ])->save();

        return back()->with('status', __('integrations.flash.webhook_rotated'));
    }

    /**
     * Was zum Verbinden gebraucht wird — und es ist je Anbieter verschieden.
     *
     * Jira: die Adresse der Instanz (jede Organisation hat ihre eigene), die
     * E-Mail-Adresse, mit der das Token erzeugt wurde (Jira Cloud will
     * Basic-Auth), und das Token. Linear: nur der API-Schlüssel — Adresse und
     * Konto stecken darin.
     *
     * @return array<string, mixed>
     */
    private static function rules(IntegrationProvider $provider): array
    {
        $rules = ['token' => ['required', 'string', 'max:500']];

        if ($provider === IntegrationProvider::Jira) {
            // `active_url` bleibt weg: es löst den Namen auf und macht aus einer
            // Formularprüfung eine Netzwerkabfrage, die in einer abgeschotteten
            // Umgebung scheitert. Ob die Adresse trägt, sagt der erste Aufruf.
            $rules['base_url'] = ['required', 'string', 'max:255', 'url:http,https'];
            $rules['email'] = ['required', 'string', 'email', 'max:255'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private static function credentials(IntegrationProvider $provider, array $validated): array
    {
        $credentials = ['token' => (string) $validated['token']];

        if ($provider === IntegrationProvider::Jira) {
            $credentials['base_url'] = rtrim((string) $validated['base_url'], '/');
            $credentials['email'] = (string) $validated['email'];
        }

        return $credentials;
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return [
            'token' => __('integrations.ticket.fields.token'),
            'base_url' => __('integrations.ticket.fields.base_url'),
            'email' => __('integrations.ticket.fields.email'),
            'default_project' => __('integrations.ticket.fields.default_project'),
            'default_type' => __('integrations.ticket.fields.default_type'),
            'default_priority' => __('integrations.ticket.fields.default_priority'),
            'default_assignee' => __('integrations.ticket.fields.default_assignee'),
        ];
    }

    private static function provider(string $provider): IntegrationProvider
    {
        $case = IntegrationProvider::tryFrom($provider);

        abort_if($case === null || ! $case->isTicketProvider(), 404);

        return $case;
    }
}
