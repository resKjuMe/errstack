<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIntegrationWebhook;
use App\Support\Integrations\GitHub\GitHubWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Der Eingang für Meldungen von GitHub (X1).
 *
 * Er tut drei Dinge und nicht mehr: Unterschrift prüfen, festhalten, einreihen.
 * **Die Antwort geht sofort hinaus** — GitHub gibt zehn Sekunden und führt die
 * Zustellung danach als fehlgeschlagen; was die Auswertung tut (Fehler
 * erledigen, Commits nachholen), dauert im Zweifel länger und läuft deshalb in
 * der Warteschlange.
 *
 * `202` und nicht `200`: angenommen, noch nicht verarbeitet. Das ist keine
 * Feinheit — es ist genau die Aussage, die stimmt, und im Zustellungsprotokoll
 * bei GitHub steht sie dann auch so.
 */
class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! GitHubWebhook::verify($request)) {
            // Ohne gültige Unterschrift wird nichts festgehalten und nichts
            // gemeldet, was ein Anrufer verwerten könnte: eine Antwort, die
            // „Repository unbekannt" von „Unterschrift falsch" unterscheidet,
            // ist eine Auskunft an jemanden, der hier nichts zu suchen hat.
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        ['event' => $event, 'fresh' => $fresh] = GitHubWebhook::record($request);

        if (! $fresh) {
            // Dieselbe Zustellung war schon da — GitHub wiederholt sie, wenn
            // die Antwort ausblieb, und auf Knopfdruck. Sie ein zweites Mal
            // auszuwerten hieße, einen von Hand wieder geöffneten Fehler
            // erneut zu erledigen.
            return response()->json(['status' => 'duplicate'], 202);
        }

        ProcessIntegrationWebhook::dispatch($event->id);

        return response()->json(['status' => 'accepted'], 202);
    }
}
