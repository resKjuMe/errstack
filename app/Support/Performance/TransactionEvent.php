<?php

namespace App\Support\Performance;

use App\Enums\SpanStatus;
use App\Models\IngestPayload;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Eine gemeldete Transaktion, geprüft und ablagefertig — samt ihrer
 * Einzelschritte.
 *
 * Hier steht das Wissen über das Sentry-Schema, und zwar an dieser einen Stelle:
 * wo der Trace-Zusammenhang steht (im Kontext, nicht im Kopf), dass der Name
 * `transaction` heißt und die Operation im Trace-Kontext, dass Zeitstempel in
 * zwei Formen kommen. Weder die Ablage noch der Verarbeitungsschritt kennt
 * davon etwas — sie arbeiten mit dieser Klasse.
 *
 * Was nicht zu deuten ist, führt zu `null` ({@see fromPayload()}); was
 * unterwegs verloren geht, wird gezählt ({@see $unreadableSpans},
 * {@see $excessSpans}), damit eine unvollständige Anzeige später erklärbar
 * bleibt und nicht als vollständig gelesen wird.
 */
final class TransactionEvent
{
    /**
     * Der Name, unter dem eine Transaktion ohne eigenen geführt wird.
     *
     * Bewusst die Zeichenkette, die Sentry dafür verwendet, und bewusst nicht
     * übersetzt: der Wert steht in der Datenbank und ist der Schlüssel, unter dem
     * zusammengefasst wird. Übersetzt gehört er in die Anzeige, nicht in die
     * Ablage — sonst hätte dieselbe Transaktion je Sprache der schreibenden
     * Anwendung einen anderen Namen.
     */
    public const UNNAMED = '<unlabeled transaction>';

    /**
     * Wie viele Messwerte je Transaktion abgelegt werden.
     */
    public const MEASUREMENT_LIMIT = 50;

    /**
     * @param  list<SpanInput>  $spans
     * @param  array<string, mixed>|null  $measurements
     * @param  int  $unreadableSpans  Gemeldete Schritte, die sich nicht deuten ließen.
     * @param  int  $excessSpans  Schritte, die über die Obergrenze hinausgingen.
     */
    private function __construct(
        public readonly string $eventId,
        public readonly string $name,
        public readonly ?string $op,
        public readonly ?string $source,
        public readonly ?string $status,
        public readonly ?string $platform,
        public readonly ?string $browser,
        public readonly ?string $device,
        public readonly ?string $country,
        public readonly ?string $environment,
        public readonly ?string $release,
        public readonly ?string $userIdentifier,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $finishedAt,
        public readonly int $durationUs,
        public readonly array $spans,
        public readonly ?array $measurements,
        public readonly int $unreadableSpans,
        public readonly int $excessSpans,
    ) {}

    /**
     * Liest eine Transaktion aus dem entpackten Rumpf einer Meldung.
     *
     * `null` heißt: als Messung unbrauchbar. Das ist genau ein Fall — es fehlt
     * Anfang oder Ende. Alles andere wird ersetzt: ein fehlender Name durch
     * {@see UNNAMED}, ein fehlender Trace-Zusammenhang durch einen aus der
     * Ereignis-Nummer abgeleiteten ({@see traceIdFrom()}). Der Grund für diese
     * Ungleichbehandlung: ohne Zeitpunkte ist nichts zu messen, während ein
     * unbenannter oder unverknüpfter Aufruf immer noch die Auskunft „hier hat
     * etwas 4 Sekunden gebraucht" trägt — und das ist die Auskunft, um die es
     * geht.
     *
     * @param  array<mixed>  $data  Der Rumpf, wie {@see IngestPayload::decoded()} ihn liefert.
     * @param  string  $eventId  Die Nummer, unter der die Meldung geführt wird.
     * @param  int  $maxSpans  Wie viele Einzelschritte höchstens abgelegt werden.
     */
    public static function fromPayload(array $data, string $eventId, int $maxSpans): ?self
    {
        $startedAt = PayloadReader::time($data['start_timestamp'] ?? null);
        $finishedAt = PayloadReader::time($data['timestamp'] ?? null);

        if ($startedAt === null || $finishedAt === null) {
            return null;
        }

        // Der Trace-Zusammenhang steht im Kontext, nicht im Kopf der Meldung —
        // dieselbe Stelle, an der ein Fehler ihn führt. Fehlt der ganze Kontext,
        // wird mit einem leeren weitergearbeitet: die Ersatzwerte unten stehen
        // dann für sich.
        $contexts = PayloadReader::map($data['contexts'] ?? null) ?? [];
        $trace = PayloadReader::map($contexts['trace'] ?? null) ?? [];

        $transactionInfo = PayloadReader::map($data['transaction_info'] ?? null) ?? [];

        [$spans, $unreadable, $excess] = self::readSpans($data['spans'] ?? null, $maxSpans);

        return new self(
            eventId: $eventId,
            name: PayloadReader::text($data['transaction'] ?? null, Transaction::NAME_LIMIT) ?? self::UNNAMED,
            op: PayloadReader::text($trace['op'] ?? null, Transaction::OP_LIMIT),
            source: PayloadReader::text($transactionInfo['source'] ?? null, 32),
            status: PayloadReader::text($trace['status'] ?? null, 32),
            platform: PayloadReader::text($data['platform'] ?? null, 32),
            browser: PayloadReader::text(
                PayloadReader::map($contexts['browser'] ?? null)['name'] ?? null,
                64,
            ),
            device: self::device($contexts['device'] ?? null),
            country: self::country($data['user'] ?? null),
            environment: PayloadReader::text($data['environment'] ?? null, 64),
            release: PayloadReader::text($data['release'] ?? null, 255),
            userIdentifier: self::userIdentifier($data['user'] ?? null),
            traceId: PayloadReader::hex($trace['trace_id'] ?? null, 32) ?? self::traceIdFrom($eventId),
            spanId: PayloadReader::hex($trace['span_id'] ?? null, 16) ?? substr($eventId, 0, 16),
            parentSpanId: PayloadReader::hex($trace['parent_span_id'] ?? null, 16),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationUs: Duration::between($startedAt, $finishedAt),
            spans: $spans,
            measurements: self::measurements($data['measurements'] ?? null),
            unreadableSpans: $unreadable,
            excessSpans: $excess,
        );
    }

    /**
     * Liest die Einzelschritte und sagt dazu, was dabei liegen blieb.
     *
     * Die Obergrenze ist kein Schutz vor Angreifern — die Größe des Elements
     * begrenzt bereits die Aufnahme —, sondern vor dem Regelfall: eine
     * Anwendung mit einer N+1-Abfrage meldet Zehntausende gleichartige Schritte
     * für einen Aufruf. Die tausend ersten zeigen das Problem genauso gut, und
     * das Ablegen der übrigen würde die Verarbeitung für alle anderen Meldungen
     * ausbremsen.
     *
     * Ein Schritt, der zweimal mit derselben Kennung gemeldet wird, wird einmal
     * abgelegt: die Ablage führt ihn eindeutig je Transaktion, und ein
     * Doppeleintrag würde die Einfügung aller Schritte scheitern lassen.
     *
     * @return array{list<SpanInput>, int, int}
     */
    private static function readSpans(mixed $raw, int $maxSpans): array
    {
        if (! is_array($raw)) {
            return [[], 0, 0];
        }

        $spans = [];
        $unreadable = 0;
        $excess = 0;

        foreach ($raw as $entry) {
            $span = is_array($entry) ? SpanInput::fromArray($entry) : null;

            if ($span === null) {
                $unreadable++;

                continue;
            }

            if (isset($spans[$span->spanId])) {
                continue;
            }

            if (count($spans) >= $maxSpans) {
                $excess++;

                continue;
            }

            $spans[$span->spanId] = $span;
        }

        return [array_values($spans), $unreadable, $excess];
    }

    /**
     * Die Kennung der betroffenen Person, in der Reihenfolge ihrer
     * Verlässlichkeit.
     *
     * Die eigene id des Nutzers bleibt über Sitzungen hinweg dieselbe, eine
     * IP-Adresse tut das nicht. Sie steht deshalb zuletzt — und überhaupt nur,
     * damit die Frage „wie viele Nutzer betrifft das" auch bei anonymen
     * Besuchern eine Antwort hat. Was davon gespeichert werden darf, entscheidet
     * später das Entfernen personenbezogener Daten (I7); hier wird nur
     * ausgewählt.
     */
    private static function userIdentifier(mixed $user): ?string
    {
        $user = PayloadReader::map($user);

        if ($user === null) {
            return null;
        }

        foreach (['id', 'username', 'email', 'ip_address'] as $field) {
            $value = PayloadReader::text($user[$field] ?? null, 255);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Das Gerät, auf dem gemessen wurde.
     *
     * Bevorzugt die **Familie** (`iPhone`, `Pixel`) und nicht das Modell
     * (`iPhone15,3`): gefragt ist „welche Art Gerät ist langsam", und mit dem
     * Modell wäre jede Aufschlüsselung eine Liste aus Dutzenden Zeilen, die
     * dasselbe sagen. Erst wenn die Familie fehlt, tritt das Modell an ihre
     * Stelle — eine ungenaue Angabe ist besser als gar keine.
     */
    private static function device(mixed $device): ?string
    {
        $device = PayloadReader::map($device);

        if ($device === null) {
            return null;
        }

        foreach (['family', 'model', 'brand'] as $field) {
            $value = PayloadReader::text($device[$field] ?? null, 64);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Das Land, aus dem gemessen wurde.
     *
     * Steht im Nutzer-Abschnitt und nicht im Kontext — dort trägt es das Schema.
     * Erwartet wird das zweistellige Kürzel (ISO 3166-1 alpha-2); alles andere
     * fällt weg, statt eine zu lange Angabe auf zwei Zeichen zu kürzen und
     * damit aus „Deutschland" das Land „De" zu machen.
     */
    private static function country(mixed $user): ?string
    {
        $user = PayloadReader::map($user);
        $geo = PayloadReader::map($user['geo'] ?? null);

        if ($geo === null) {
            return null;
        }

        $code = PayloadReader::text($geo['country_code'] ?? null, 8);

        return $code !== null && preg_match('/^[A-Za-z]{2}$/', $code) === 1
            ? strtoupper($code)
            : null;
    }

    /**
     * Die Messwerte, auf die Zahlenwerte reduziert.
     *
     * Sentry führt jeden Messwert als `{"value": 1234.5, "unit": "millisecond"}`,
     * und genau so bleibt er liegen — die Einheit gehört dazu, sonst ist der Wert
     * nicht zu deuten. Aussortiert wird, was keinen Zahlenwert hat: eine
     * Bewertung („gut", „schlecht") ist die Aufgabe der Auswertung (PF5) und
     * darf nicht als Messwert durchgehen.
     *
     * @return array<string, mixed>|null
     */
    private static function measurements(mixed $raw): ?array
    {
        $raw = PayloadReader::map($raw);

        if ($raw === null) {
            return null;
        }

        $measurements = [];

        foreach ($raw as $name => $entry) {
            $entry = PayloadReader::map($entry);
            $value = $entry['value'] ?? null;

            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            if (! is_finite((float) $value)) {
                continue;
            }

            $measurements[(string) $name] = [
                'value' => (float) $value,
                'unit' => PayloadReader::text($entry['unit'] ?? null, 32),
            ];

            // Die Zahl der Messwerte ist im Schema nicht begrenzt, die Spalte
            // aber schon. Mehr als das braucht keine Auswertung; ohne Grenze
            // genügte ein SDK-Fehler, um die Zeile zu sprengen.
            if (count($measurements) >= self::MEASUREMENT_LIMIT) {
                break;
            }
        }

        return $measurements === [] ? null : $measurements;
    }

    /**
     * Ein Trace-Zusammenhang für eine Transaktion, die keinen mitgebracht hat.
     *
     * Abgeleitet aus der Ereignis-Nummer und nicht neu gezogen: dieselbe Meldung
     * ergibt so bei jedem Durchlauf dieselbe Kennung. Eine zufällige würde beim
     * erneuten Verarbeiten derselben Rohdaten — nach einem Fehlschlag der
     * ausdrücklich vorgesehene Fall — einen zweiten Trace erzeugen.
     *
     * Die Form passt: eine Ereignis-Nummer ist 32 Hex-Zeichen, eine Trace-Kennung
     * auch.
     */
    private static function traceIdFrom(string $eventId): string
    {
        return $eventId;
    }

    /**
     * Zählt dieser Ausgang als Fehlschlag?
     */
    public function failed(): bool
    {
        return SpanStatus::isFailureValue($this->status);
    }
}
