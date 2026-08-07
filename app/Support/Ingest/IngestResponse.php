<?php

namespace App\Support\Ingest;

use Illuminate\Http\JsonResponse;

/**
 * Antworten der Datenaufnahme in der Form, die Sentry-SDKs erwarten.
 *
 * Zwei Dinge sind dabei nicht verhandelbar, weil die SDKs sie fest verdrahtet
 * haben:
 *
 *   • Die Bestätigung ist `{"id": "<event_id>"}` — genau dieses Feld liest ein
 *     SDK aus, um die Nummer zu protokollieren.
 *   • Bei einer Abweisung steht der Grund in der Kopfzeile `X-Sentry-Error`.
 *     Dort suchen die SDKs ihn; im Rumpf lesen sie ihn nicht. Steht er nur im
 *     Rumpf, meldet das SDK der entwickelnden Person „HTTP 401" ohne Grund.
 *
 * Der Rumpf einer Abweisung trägt den Grund zusätzlich unter `detail` — so hält
 * es die Sentry-Gegenstelle auch, und wer mit `curl` prüft, sieht ihn sofort.
 */
final class IngestResponse
{
    /** Kopfzeile, in der Sentry den Grund einer Abweisung mitteilt. */
    public const ERROR_HEADER = 'X-Sentry-Error';

    /**
     * Angenommen. Kein 201: Sentry antwortet mit 200, und einzelne SDKs prüfen
     * genau darauf.
     */
    public static function accepted(string $eventId): JsonResponse
    {
        return new JsonResponse(['id' => $eventId], 200);
    }

    /**
     * Abgewiesen. Der Grund geht in Kopfzeile und Rumpf.
     *
     * Die Kopfzeile wird von Zeilenumbrüchen befreit: sie entsteht teils aus
     * Meldungstexten, und ein Umbruch darin würde die Antwort zerlegen.
     */
    public static function error(string $reason, int $status): JsonResponse
    {
        $header = trim(preg_replace('/\s+/', ' ', $reason) ?? '');

        return new JsonResponse(['detail' => $reason], $status, [
            self::ERROR_HEADER => $header,
        ]);
    }
}
