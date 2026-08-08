<?php

namespace App\Http\Controllers;

use App\Support\Operations\HealthCheck;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/health` — die Auskunft an Ladeverteiler, Container-Verwaltung und jede
 * fremde Überwachung: nimmt diese Installation noch Arbeit an?
 *
 * Ohne Anmeldung, und deshalb ohne jede innere Auskunft. Die Antwort nennt je
 * Bestandteil „ok" oder „failed" und sonst nichts — keine Fehlermeldung, keine
 * Laufzeiten, keine Zahlen. Der Grund ist nicht Prüderie: eine Fehlermeldung
 * einer Datenbank nennt Rechnername, Datenbanknamen und oft den Benutzer, und
 * wer die Adresse errät, bekäme sie frei Haus. Wer den Grund braucht, findet
 * ihn im Log oder in der Betriebsansicht — beides hinter einer Anmeldung.
 *
 * Der Statuscode ist die eigentliche Auskunft: `200`, solange alles antwortet,
 * sonst `503`. Ein Ladeverteiler liest nichts anderes.
 *
 * Laravels eingebaute Adresse `/up` bleibt daneben bestehen. Sie prüft nichts
 * und beantwortet damit eine andere Frage — „läuft hier überhaupt PHP" gegen
 * „kann diese Installation arbeiten".
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $checks = $health->run();
        $overall = HealthCheck::overall($checks);

        return response()->json([
            'status' => $overall->value,
            'checks' => array_map(
                static fn (array $check): string => $check['state']->value,
                $checks,
            ),
        ], $overall->isOk() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE, [
            // Ein zwischengespeicherter Zustandsbericht ist schlimmer als
            // keiner: er meldet „ok", während die Datenbank längst weg ist.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
