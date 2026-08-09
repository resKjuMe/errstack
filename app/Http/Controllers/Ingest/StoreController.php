<?php

namespace App\Http\Controllers\Ingest;

use App\Exceptions\IngestRejection;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use App\Support\Ingest\IngestBody;
use App\Support\Ingest\IngestContext;
use App\Support\Ingest\IngestResponse;
use App\Support\Ingest\Spikes\SpikeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * Der klassische Aufnahme-Endpunkt: `POST /api/{project}/store/`.
 *
 * Er nimmt an und legt ab — nicht mehr. Keine Auswertung, kein Gruppieren, kein
 * Benachrichtigen; das läuft später im Hintergrund. Der Grund ist die
 * Antwortzeit der überwachten Anwendung: sie wartet auf diese Antwort, während
 * ihr eigener Fehler gerade behandelt wird. Was hier an Arbeit hinzukäme,
 * bezahlt jede Anfrage der überwachten Anwendung mit — und zwar genau dann,
 * wenn dort schon etwas schiefläuft.
 *
 * Die Adresse ist Sentrys Adresse, samt Projektnummer im Pfad und Antwort
 * `{"id": "…"}`. Damit meldet ein unverändertes Sentry-SDK hierher, sobald in
 * seiner DSN dieser Host steht.
 */
class StoreController extends Controller
{
    public function __construct(private readonly SpikeGuard $spikes) {}

    public function store(Request $request): JsonResponse
    {
        $payload = IngestBody::decode($request);

        // Als Objekt gelesen, nicht als Feld-Baum: `json_decode(…, true)` kann
        // ein leeres Objekt nicht von einer leeren Liste unterscheiden — beides
        // wäre `[]` —, und eine Liste ist keine Meldung.
        $event = json_decode($payload);

        if (! $event instanceof stdClass) {
            throw IngestRejection::unreadable('ingest.not_json');
        }

        // Die Nummer kommt vom SDK. Fehlt sie oder ist sie unbrauchbar, vergeben
        // wir eine — wie Sentry. Ohne Nummer in der Antwort hätte der Absender
        // keine Kennung, unter der er seine Meldung wiederfindet.
        $eventId = IngestPayload::normalizeEventId($event->event_id ?? null)
            ?? IngestPayload::freshEventId();

        // Der Ausschlag-Schutz (A7). Er steht vor dem Ablegen und trotzdem
        // hinter der Nummer: die Antwort bleibt in jedem Fall eine 200 mit
        // Kennung. Ein SDK, das eine Ablehnung sieht, versucht es erneut — und
        // eine Wiederholung ist das Letzte, was eine Anwendung braucht, die
        // gerade zu viel meldet. Stillschweigend verschwindet dabei nichts: der
        // Schutz zählt jedes Ereignis, das er nimmt.
        if (! $this->spikes->allows(IngestContext::key($request))) {
            return IngestResponse::accepted($eventId);
        }

        $accepted = IngestPayload::accept(
            key: IngestContext::key($request),
            eventId: $eventId,
            payload: $payload,
            sdk: IngestContext::client($request),
        );

        // Die Auswertung läuft im Hintergrund. Eingereiht wird erst nach dem
        // Ablegen: ein Arbeiter kann schneller sein als der Rest dieser Anfrage,
        // und er braucht die Zeile.
        ProcessIngestPayload::dispatch($accepted);

        return IngestResponse::accepted($eventId);
    }
}
