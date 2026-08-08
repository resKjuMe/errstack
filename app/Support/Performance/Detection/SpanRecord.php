<?php

namespace App\Support\Performance\Detection;

use App\Models\TransactionSpan;

/**
 * Ein Einzelschritt eines Ablaufs, aufbereitet für die Erkennung.
 *
 * **Warum nicht direkt das Modell:** die Erkenner vergleichen Schritte
 * paarweise — bei fünfhundert Schritten sind das bis zu hunderttausend
 * Vergleiche, und jeder davon fragt nach Anfang, Ende, Vorgang und Abfrageform.
 * Ein Eloquent-Modell beantwortet jede dieser Fragen über `__get`, und die
 * Abfrageform müsste bei jedem Vergleich neu normalisiert werden. Hier steht
 * alles einmal ausgerechnet als Feld.
 *
 * Die Umrechnung in Millisekunden passiert bewusst **nicht**: gerechnet wird
 * durchgehend in Mikrosekunden, so wie es in der Datenbank steht. Umgerechnet
 * wird erst in der Anzeige.
 */
final class SpanRecord
{
    /**
     * Wie viel Überlappung noch als „nacheinander" durchgeht — eine
     * Millisekunde, die Auflösung der gemeldeten Zeitstempel.
     */
    private const OVERLAP_TOLERANCE_US = 1_000;

    /**
     * @param  string  $spanId  Kennung dieses Schritts im Ablauf.
     * @param  string|null  $parentSpanId  Der umschließende Schritt.
     * @param  string|null  $op  Der Vorgang (`db.query`, `http.client`, …).
     * @param  string|null  $description  Was der Schritt getan hat — bei einer Abfrage das SQL.
     * @param  int  $startedUs  Anfang, in Mikrosekunden seit dem Anfang des Ablaufs.
     * @param  int  $durationUs  Dauer in Mikrosekunden.
     * @param  int  $position  Die Reihenfolge, in der das SDK den Schritt gemeldet hat.
     * @param  array<string, mixed>  $data  Zusatzangaben des SDK.
     * @param  string  $shape  Die normalisierte Abfrageform ({@see QueryShape}).
     */
    public function __construct(
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly ?string $op,
        public readonly ?string $description,
        public readonly int $startedUs,
        public readonly int $durationUs,
        public readonly int $position,
        public readonly array $data,
        public readonly string $shape,
    ) {}

    public static function fromModel(TransactionSpan $span, int $originUs): self
    {
        $description = $span->description;

        return new self(
            spanId: (string) $span->span_id,
            parentSpanId: $span->parent_span_id === null ? null : (string) $span->parent_span_id,
            op: $span->op === null ? null : (string) $span->op,
            description: $description,
            // Der Anfang **relativ** zum Ablauf. Absolute Zeitstempel in
            // Mikrosekunden überschreiten auf 32-Bit-Systemen den ganzzahligen
            // Bereich, und gebraucht wird ohnehin nur der Abstand: alle
            // Vergleiche der Erkenner sind Vergleiche innerhalb eines Ablaufs.
            startedUs: (int) round($span->started_at->getPreciseTimestamp(6)) - $originUs,
            durationUs: max(0, (int) $span->duration_us),
            position: (int) $span->position,
            data: is_array($span->data) ? $span->data : [],
            shape: QueryShape::of($description),
        );
    }

    public function endsUs(): int
    {
        return $this->startedUs + $this->durationUs;
    }

    /**
     * Beginnt dieser Schritt erst, nachdem der andere fertig war?
     *
     * Die Frage hinter „nacheinander statt gleichzeitig": zwei Abfragen, die
     * sich überlappen, laufen parallel und kosten zusammen nur die längere von
     * beiden. Erst wenn die zweite auf die erste wartet, addieren sich die
     * Wartezeiten — und nur dann ist etwas zu holen.
     *
     * Die Toleranz ist bewusst großzügig: die Zeitstempel des SDK sind auf
     * Millisekunden gerundet, und zwei streng nacheinander laufende Abfragen
     * können sich dadurch um Bruchteile überlappen.
     */
    public function follows(self $other): bool
    {
        return $this->startedUs + self::OVERLAP_TOLERANCE_US >= $other->endsUs();
    }

    /**
     * Ein Wert aus den Zusatzangaben des SDK, als ganze Zahl.
     *
     * Die Angaben kommen aus einer fremden Anwendung: dieselbe Größe steht
     * einmal als Zahl und einmal als Zeichenkette darin, je nach SDK und
     * Fassung. Wer sie ungeprüft nimmt, vergleicht `"1024"` mit `1024` und
     * bekommt bei jedem zweiten SDK ein anderes Ergebnis.
     */
    public function intData(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return null;
    }

    /**
     * Ein Wahrheitswert aus den Zusatzangaben — mit denselben Vorbehalten.
     *
     * `"false"` als Zeichenkette ist in PHP wahr. Genau daran scheitert die
     * naive Prüfung auf einen Cache-Treffer, und zwar in die schlimmere
     * Richtung: jeder Fehlgriff sähe wie ein Treffer aus und die Erkennung
     * fände nie etwas.
     */
    public function boolData(string $key): ?bool
    {
        $value = $this->data[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => null,
            };
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return null;
    }

    public function stringData(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Gehört dieser Schritt zu einem der genannten Vorgänge?
     *
     * Verglichen wird auf **Präfix** und nicht auf Gleichheit: die SDKs
     * verfeinern den Vorgang je Treiber (`db.sql.query`, `db.redis`,
     * `http.client.stream`), und eine Liste, die jede Ausprägung aufzählt, ist
     * beim nächsten SDK unvollständig.
     *
     * @param  list<string>  $prefixes
     */
    public function isOp(array $prefixes): bool
    {
        if ($this->op === null) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if ($this->op === $prefix || str_starts_with($this->op, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }
}
