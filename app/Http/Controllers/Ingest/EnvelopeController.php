<?php

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Support\Ingest\Envelope;
use App\Support\Ingest\EnvelopeIntake;
use App\Support\Ingest\IngestBody;
use App\Support\Ingest\IngestContext;
use App\Support\Ingest\IngestResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Der moderne Aufnahme-Endpunkt: `POST /api/{project}/envelope/`.
 *
 * Ein Envelope ist der Weg, den heutige Sentry-SDKs nehmen. Er bündelt in einer
 * Anfrage, was früher mehrere waren: die Fehlermeldung, die Transaktion mit
 * ihren Einzelschritten, die Sitzung für die Release-Gesundheit, den Screenshot
 * dazu — und die Auskunft darüber, was das SDK selbst schon verworfen hat.
 * Für die überwachte Anwendung ist das eine Verbindung statt fünf; für uns
 * heißt es, dass ein Envelope Elemente ganz unterschiedlicher Art enthält.
 *
 * Wie beim klassischen Weg wird hier **nichts ausgewertet**: die Elemente
 * werden auseinandergenommen und einzeln in dieselbe Eingangsablage gelegt, mit
 * ihrem jeweiligen Typ. Was daraus wird — Gruppierung, Trace, Release-Gesundheit
 * —, entscheidet die Verarbeitung im Hintergrund. Der Grund ist derselbe: die
 * überwachte Anwendung wartet auf diese Antwort, während bei ihr gerade etwas
 * schiefläuft.
 *
 * Die Antwort ist bewusst großzügig. Solange die Kopfzeile lesbar ist, gibt es
 * eine 200 — auch wenn einzelne Elemente dabei verworfen wurden. Ein SDK
 * schickt einen abgewiesenen Envelope nicht erneut; eine 400 wegen eines
 * unbekannten Element-Typs würde deshalb die heilen Elemente gleich mit
 * vernichten.
 */
class EnvelopeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $raw = IngestBody::decode(
            $request,
            requestLimit: (int) config('ingest.envelope.max_request_bytes'),
            payloadLimit: (int) config('ingest.envelope.max_payload_bytes'),
        );

        $envelope = Envelope::parse($raw);

        $eventId = EnvelopeIntake::fromConfig()->accept(
            envelope: $envelope,
            key: IngestContext::key($request),
            client: IngestContext::client($request),
        );

        return IngestResponse::envelopeAccepted($eventId);
    }
}
