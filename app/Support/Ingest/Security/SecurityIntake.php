<?php

namespace App\Support\Ingest\Security;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\ProjectKey;
use App\Support\Ingest\EnvelopeIntake;
use Illuminate\Support\Facades\Log;

/**
 * Nimmt einen Sicherheitsbericht des Browsers an: filtern, umwandeln, ablegen.
 *
 * Dasselbe Zuschneiden wie bei den Envelopes ({@see EnvelopeIntake}): der
 * Endpunkt liest die Anfrage, die Aufnahme entscheidet, was daraus wird. Der
 * Grund ist derselbe — die Entscheidung soll sich ohne HTTP prüfen lassen, und
 * sie soll an einer Stelle stehen, wenn später ein zweiter Weg dazukommt (die
 * Reporting-API schickt dieselben Berichte gebündelt an eine andere Adresse).
 *
 * **Der Rausch-Filter steht vor dem Ablegen und nicht in der Verarbeitungskette.**
 * Das weicht bewusst von der Reihenfolge für SDK-Meldungen ab, wo zuerst
 * abgelegt und dann gefiltert wird. Zwei Gründe: der Anteil ist ein anderer —
 * bei einem `report-uri` im offenen Netz sind die Berichte aus Erweiterungen
 * die Mehrheit, nicht die Ausnahme —, und der Bericht kommt ohne SDK. Es gibt
 * niemanden, der eine verworfene Meldung später erneut schickt, und niemanden,
 * dem die Rohdaten etwas nützen: was in ihnen steht, ist die Adresse einer
 * Erweiterung. Gezählt wird die Verwerfung trotzdem, unter derselben Kategorie
 * wie beim Eingangsfilter — sonst wäre „warum sehe ich keine CSP-Berichte?"
 * nicht zu beantworten.
 */
final class SecurityIntake
{
    /**
     * Nimmt den Bericht an — oder verwirft ihn als Rauschen (`null`).
     *
     * @param  array<string, string>  $headers  Kopfzeilen der meldenden Anfrage
     */
    public function accept(ProjectKey $key, SecurityReport $report, array $headers = []): ?IngestPayload
    {
        $noise = ExtensionNoise::match($report);

        if ($noise !== null) {
            $this->discard($key, $report, $noise);

            return null;
        }

        // Eine eigene Nummer: der Bericht bringt keine mit, und ohne sie hätte
        // die Meldung keine Kennung, unter der sie wiederzufinden ist.
        $eventId = IngestPayload::freshEventId();

        $accepted = IngestPayload::accept(
            key: $key,
            eventId: $eventId,
            payload: (string) json_encode($report->toEvent($eventId, now(), $headers)),
            type: IngestType::Event,
            // Kein SDK, aber auch nicht „unbekannt": in der Eingangsablage soll
            // erkennbar bleiben, dass diese Meldung aus einem Bericht des
            // Browsers entstanden ist und nicht aus einem Absturz.
            sdk: SecurityReport::SDK_NAME.'/'.$report->type()->value,
        );

        ProcessIngestPayload::dispatch($accepted);

        return $accepted;
    }

    /**
     * Zählen und protokollieren in einem — wie bei den Envelopes: die Zahl
     * sagt, wie oft es passiert, die Protokollzeile sagt, was genau.
     */
    private function discard(ProjectKey $key, SecurityReport $report, string $matched): void
    {
        IngestDiscard::server($key, DiscardReason::Filtered, ExtensionNoise::category());

        Log::info('Sicherheitsbericht verworfen: '.$report->type()->label(), [
            'projekt' => $key->project_id,
            'schluessel' => $key->id,
            'art' => $report->type()->value,
            'erkannt_an' => $matched,
        ]);
    }
}
