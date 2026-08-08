<?php

namespace App\Http\Controllers\Ingest;

use App\Enums\IngestType;
use App\Exceptions\IngestRejection;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use App\Models\UserReport;
use App\Support\Feedback\UserReportPayload;
use App\Support\Ingest\IngestBody;
use App\Support\Ingest\IngestContext;
use App\Support\Ingest\IngestResponse;
use App\Support\Ingest\Processing\Steps\RecordUserReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Der Endpunkt für Rückmeldungen: `POST /api/{project}/user-feedback/`.
 *
 * Die Adresse ist Sentrys Adresse, damit der Absturzbericht eines unveränderten
 * SDK hier landet, sobald in seiner DSN dieser Host steht. Denselben Weg nimmt
 * das mitgelieferte Widget (`public/widget/feedback.js`), nur ohne
 * Ereignisnummer.
 *
 * Wie überall in der Datenaufnahme wird hier **nichts ausgewertet**: der Rumpf
 * wird abgelegt und eingereiht, die Zeile in der Liste entsteht im Hintergrund
 * ({@see RecordUserReport}). Der Grund ist
 * hier ein anderer als sonst — es wartet keine abstürzende Anwendung, sondern
 * ein Mensch vor einem Formular —, das Ergebnis aber dasselbe: ein Weg, eine
 * Verarbeitung, dieselben Zusagen.
 *
 * **Zwei Formen kommen an, und beide werden angenommen:** JSON, wie es die SDKs
 * schicken, und ein abgeschicktes Formular. Das Widget kann kein JSON mit
 * eigener Kopfzeile schicken, ohne eine Vorab-Anfrage (OPTIONS) auszulösen —
 * dieselbe Überlegung, aus der der Schlüssel dort im Abfrageteil steht.
 *
 * Der Rumpf wird schon hier gelesen, obwohl er auch später gelesen wird. Das
 * ist kein Doppel, sondern der Unterschied zwischen den Gegenstellen: eine
 * Anwendung schickt weg und sieht nie wieder hin, ein Mensch wartet auf eine
 * Antwort. Eine leere Zuschrift muss deshalb sofort ein „nein" bekommen und
 * nicht stillschweigend in der Warteschlange verschwinden.
 */
class UserFeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $this->body($request);

        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || UserReportPayload::fromArray($decoded) === null) {
            throw IngestRejection::unreadable('ingest.feedback_incomplete');
        }

        $accepted = IngestPayload::accept(
            key: IngestContext::key($request),
            // Die genannte Ereignisnummer ist die Kennung, unter der diese
            // Zuschrift geführt wird — so findet ein Beleg später zu der
            // Meldung, um die es geht. Ohne genannte Nummer wird eine vergeben:
            // eine freie Zuschrift gehört zu keinem Ereignis, braucht aber eine
            // Kennung wie jeder andere Datensatz auch.
            eventId: IngestPayload::normalizeEventId($decoded['event_id'] ?? null)
                ?? IngestPayload::freshEventId(),
            payload: $payload,
            type: IngestType::UserReport,
            sdk: IngestContext::client($request),
        );

        ProcessIngestPayload::dispatch($accepted);

        return IngestResponse::accepted($accepted->event_id);
    }

    /**
     * Der Rumpf als JSON — auch dann, wenn er als Formular ankam.
     *
     * Ein Formular wird in die JSON-Form gebracht, statt beide Formen durch die
     * ganze Kette zu schleifen: ab der Ablage soll es nur noch **eine** Form
     * geben, sonst braucht jeder spätere Schritt eine Fallunterscheidung für
     * eine Herkunft, die ihn nichts angeht.
     */
    private function body(Request $request): string
    {
        $raw = $request->getContent();

        if (strlen($raw) > self::limit()) {
            throw IngestRejection::tooLarge(self::limit());
        }

        // JSON geht **unverändert** weiter: die Rohdaten sind der Beleg, und ein
        // hier zusammengesetzter Rumpf wäre keiner mehr. `IngestBody` übernimmt
        // dabei das Entpacken und die Grenzen, wie bei jedem anderen Weg auch.
        if ($request->isJson() || str_starts_with(ltrim($raw), '{')) {
            return IngestBody::decode($request, requestLimit: self::limit(), payloadLimit: self::limit());
        }

        // Ein abgeschicktes Formular. Nur die bekannten Felder, jedes auf die
        // Länge seiner Spalte gekürzt — ein Formularfeld ist so lang, wie der
        // Absender will.
        $form = [];

        foreach (['event_id', 'name', 'email', 'comments', 'message', 'url'] as $field) {
            $value = $request->input($field);

            if (is_string($value) && trim($value) !== '') {
                $form[$field] = mb_substr(trim($value), 0, UserReport::COMMENTS_LIMIT);
            }
        }

        if ($form === []) {
            throw IngestRejection::unreadable('ingest.feedback_incomplete');
        }

        return (string) json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Die Größengrenze einer Rückmeldung.
     *
     * Nicht die einer Fehlermeldung (1 MiB): dort steckt ein Stacktrace mit
     * Quelltextzeilen drin, hier ein getippter Absatz. Großzügig gerechnet ist
     * das Vierfache der längsten zulässigen Zuschrift immer noch klein genug,
     * dass ein Massenversand daran auffällt.
     */
    private static function limit(): int
    {
        return UserReport::COMMENTS_LIMIT * 4;
    }
}
