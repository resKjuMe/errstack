<?php

namespace App\Support\Ingest;

use Illuminate\Http\JsonResponse;
use stdClass;

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
     * Ein Envelope ist angenommen.
     *
     * Enthielt er eine Meldung, geht deren Nummer wie gewohnt zurück. Enthielt
     * er keine — ein Envelope aus lauter Sitzungen oder eine Verworfen-Meldung
     * bringt keine mit —, bleibt der Rumpf ein leeres Objekt statt
     * `{"id":null}`: eine Nummer zu nennen, unter der nichts zu finden ist,
     * wäre die schlechtere Auskunft, und die SDKs kommen mit beidem zurecht.
     *
     * `stdClass` statt `[]`, weil ein leeres Feld-Array als `[]` ausgegeben
     * würde — eine Liste, wo ein Objekt erwartet wird.
     */
    public static function envelopeAccepted(?string $eventId): JsonResponse
    {
        return new JsonResponse($eventId === null ? new stdClass : ['id' => $eventId], 200);
    }

    /**
     * Ein Sicherheitsbericht des Browsers ist angenommen.
     *
     * `201` ohne Nummer, wie es auch die Sentry-Gegenstelle hält. Die Nummer
     * fehlt nicht aus Versehen: der Bericht bringt keine mit, und die, die wir
     * vergeben, hilft dem Absender nicht — dort steht kein SDK, das sie
     * protokollieren könnte, sondern ein Browser, der die Antwort verwirft.
     *
     * Dieselbe Antwort auch für einen als Rauschen verworfenen Bericht: was ein
     * Browser mit einem Fehlercode täte, ist unbestimmt, und in der Konsole der
     * besuchten Seite stünde er ausgerechnet dort, wo nichts zu tun ist.
     */
    public static function securityAccepted(): JsonResponse
    {
        return new JsonResponse(new stdClass, 201);
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
