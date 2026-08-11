<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessIntegrationWebhook;
use App\Models\Integration;
use App\Support\Integrations\Tickets\TicketWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Der Eingang für Meldungen der Ticket-Systeme (X4).
 *
 * Er tut dasselbe wie der von GitHub und in derselben Reihenfolge: ausweisen,
 * festhalten, einreihen. **Die Antwort geht sofort hinaus** — was die Auswertung
 * tut (Fehler erledigen), dauert im Zweifel länger als die Geduld des Anrufers,
 * und ein Zeitablauf drüben hieße: dieselbe Meldung kommt gleich noch einmal,
 * während die erste noch läuft.
 *
 * `202` und nicht `200`: angenommen, noch nicht verarbeitet. Das ist keine
 * Feinheit — es ist genau die Aussage, die stimmt.
 *
 * **Ausgewiesen wird über das Geheimnis in der Adresse** und nicht über eine
 * Unterschrift; warum, steht in {@see TicketWebhook}. Es identifiziert die
 * Anbindung und beweist die Herkunft in einem — deshalb steht danach die
 * Organisation fest, ohne dass die Nutzlast gelesen wäre.
 *
 * Der Anbieter kommt aus der **Route** und nicht aus der Nutzlast. Eine Adresse
 * je Anbieter ist die eine Stelle, an der man beim Einrichten nichts verwechseln
 * kann — und sie verhindert, dass eine Jira-Nutzlast über die Linear-Adresse
 * hereinkommt und dort nach Feldern gelesen wird, die es nicht gibt.
 */
class TicketWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, string $token): JsonResponse
    {
        $integration = self::integration($provider, $token);

        if ($integration === null) {
            // Kein Wort darüber, was nicht gepasst hat. Eine Antwort, die
            // „Anbieter unbekannt" von „Geheimnis falsch" unterscheidet, ist eine
            // Auskunft an jemanden, der hier nichts zu suchen hat — und mit
            // genug Versuchen eine Anleitung.
            return response()->json(['message' => 'Invalid webhook address'], 401);
        }

        ['event' => $event, 'fresh' => $fresh] = TicketWebhook::record($request, $integration);

        if (! $fresh) {
            // Dieselbe Zustellung war schon da. Linear wiederholt sie, wenn die
            // Antwort ausblieb; Jira ebenso. Sie ein zweites Mal auszuwerten
            // hieße, einen von Hand wieder geöffneten Fehler erneut zu erledigen.
            return response()->json(['status' => 'duplicate'], 202);
        }

        ProcessIntegrationWebhook::dispatch($event->id);

        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * Die Anbindung hinter Anbieter und Geheimnis.
     *
     * **Der Zustand der Anbindung wird hier nicht geprüft.** Eine Meldung wird
     * auch dann angenommen, wenn das Token drüben zurückgezogen wurde: der
     * eingehende Abgleich braucht keinen Zugang (die Nutzlast bringt alles mit),
     * und ein Eingang, der bei verlorener Verbindung schweigt, verliert genau die
     * Meldungen, an denen man später sehen würde, dass etwas ankam.
     */
    private static function integration(string $provider, string $token): ?Integration
    {
        $case = IntegrationProvider::tryFrom($provider);

        if ($case === null || ! $case->isTicketProvider()) {
            return null;
        }

        return Integration::byWebhookToken($case, $token);
    }
}
