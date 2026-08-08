<?php

namespace App\Support\Performance;

/**
 * Die Verteilung von Dauern als Häufigkeiten über festen Klassen.
 *
 * Der Zweck sind Perzentile über beliebige Zeiträume. Ein p95 lässt sich nicht
 * addieren: aus dem p95 von sechzig Minuten wird nicht das p95 der Stunde. Eine
 * Verteilung lässt sich addieren — Klasse für Klasse —, und daraus ist jedes
 * Perzentil zu lesen. Deshalb steht in der Vorberechnung diese Häufigkeitstabelle
 * und keine fertige Kennzahl.
 *
 * Die Klassen wachsen **logarithmisch**: jede ist doppelt so breit wie die
 * vorige. Das passt zu den Daten, die gemessen werden sollen — bei 40 ms sind
 * 5 ms Unterschied viel, bei 40 s sind sie belanglos. Gleich breite Klassen
 * müssten dagegen für den ganzen Bereich von Mikrosekunden bis Minuten so fein
 * sein, dass es Zehntausende wären.
 *
 * Der Preis ist eine bekannte, begrenzte Ungenauigkeit: innerhalb einer Klasse
 * ist nicht mehr zu erkennen, wo ein Wert lag. Der Fehler eines Perzentils ist
 * damit höchstens die Breite seiner Klasse — bei einer Verdopplung je Klasse
 * also höchstens 50 % nach unten. Für die Frage „ist diese Seite langsam
 * geworden" ist das die richtige Abwägung; für den einzelnen Aufruf steht die
 * genaue Dauer weiterhin an der Transaktion.
 */
final class DurationHistogram
{
    /**
     * Untergrenze der ersten Klasse in Mikrosekunden.
     *
     * Alles darunter (100 µs) landet zusammen in Klasse 0. Feiner aufzulösen
     * hätte keinen Wert: eine Transaktion, die schneller als eine Zehntel-
     * Millisekunde ist, ist nicht das Problem, das gesucht wird.
     */
    public const BASE_US = 100;

    /**
     * Höchste Klasse. Klasse 30 beginnt bei 100 µs · 2^30, also rund 30 Stunden
     * — was länger läuft, ist keine Antwortzeit mehr, sondern ein Hänger, und
     * wird in der letzten Klasse gesammelt.
     */
    public const MAX_BUCKET = 30;

    /**
     * @param  array<int, int>  $buckets  Klasse → Häufigkeit.
     */
    private function __construct(
        private array $buckets = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * Liest eine abgelegte Verteilung wieder ein.
     *
     * Nachsichtig gegenüber allem, was nicht passt: die Spalte ist ein
     * Feld-Baum, und eine kaputte Zelle darf die laufende Aufnahme nicht
     * anhalten. Was nicht zu deuten ist, fällt weg — die Verteilung baut sich
     * aus den folgenden Messungen wieder auf.
     *
     * @param  mixed  $stored  Der Inhalt der Spalte `duration_histogram`.
     */
    public static function fromStored(mixed $stored): self
    {
        if (! is_array($stored)) {
            return new self;
        }

        $buckets = [];

        foreach ($stored as $bucket => $count) {
            if (! is_numeric($bucket) || ! is_int($count) || $count < 1) {
                continue;
            }

            $index = (int) $bucket;

            if ($index < 0 || $index > self::MAX_BUCKET) {
                continue;
            }

            $buckets[$index] = ($buckets[$index] ?? 0) + $count;
        }

        return new self($buckets);
    }

    /**
     * Nimmt eine Messung auf.
     */
    public function add(int $durationUs, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $bucket = self::bucketFor($durationUs);

        $this->buckets[$bucket] = ($this->buckets[$bucket] ?? 0) + $count;
    }

    /**
     * Legt eine zweite Verteilung auf diese — die Rechenoperation, um derer
     * willen die Verteilung überhaupt abgelegt wird.
     */
    public function merge(self $other): void
    {
        foreach ($other->buckets as $bucket => $count) {
            $this->buckets[$bucket] = ($this->buckets[$bucket] ?? 0) + $count;
        }
    }

    /**
     * Die Klasse, in die eine Dauer fällt.
     *
     * Die Umkehrung von `BASE_US · 2^n`: die Zahl der Verdopplungen zwischen der
     * ersten Klasse und dem Wert.
     */
    public static function bucketFor(int $durationUs): int
    {
        if ($durationUs <= self::BASE_US) {
            return 0;
        }

        $bucket = (int) floor(log($durationUs / self::BASE_US, 2)) + 1;

        return min($bucket, self::MAX_BUCKET);
    }

    /**
     * Untergrenze einer Klasse in Mikrosekunden — was eine Auswertung braucht,
     * um die Verteilung als Balken zu zeichnen (PF3).
     */
    public static function lowerBound(int $bucket): int
    {
        return $bucket <= 0 ? 0 : (int) (self::BASE_US * 2 ** ($bucket - 1));
    }

    /**
     * Das Perzentil einer Verteilung in Mikrosekunden, oder `null`, wenn nichts
     * gemessen wurde.
     *
     * Zurückgegeben wird die **Obergrenze** der Klasse, in der das Perzentil
     * liegt. Das ist die vorsichtige Richtung: eine Antwortzeit lieber zu hoch
     * als zu niedrig ausweisen, damit ein Schwellwert-Alarm (A3) nicht wegen der
     * Klassenbreite ausbleibt.
     *
     * @param  float  $percentile  Zwischen 0 und 1 — 0.95 für das p95.
     */
    public function percentile(float $percentile): ?int
    {
        $total = $this->count();

        if ($total === 0) {
            return null;
        }

        $percentile = max(0.0, min(1.0, $percentile));

        // Aufgerundet, damit das p100 tatsächlich die letzte Messung trifft und
        // nicht die vorletzte.
        $target = (int) max(1, ceil($percentile * $total));

        $seen = 0;
        $buckets = $this->buckets;
        ksort($buckets);

        foreach ($buckets as $bucket => $count) {
            $seen += $count;

            if ($seen >= $target) {
                return self::lowerBound($bucket + 1);
            }
        }

        return self::lowerBound(self::MAX_BUCKET + 1);
    }

    /**
     * Je Klasse eine Summe, als SQL-Ausdruck — der Weg, Verteilungen **in der
     * Datenbank** zusammenzulegen statt alle Zeilen zu laden.
     *
     * `JSON_EXTRACT` gibt es in MySQL wie in SQLite, und die Klassen sind
     * bekannt und endlich: 31 Ausdrücke, aus der Konstanten erzeugt statt
     * einunddreißigmal hingeschrieben. Der Pfad steht in Anführungszeichen
     * (`$."7"`), weil eine reine Ziffer als Feldname sonst in keiner der beiden
     * Datenbanken zu deuten ist; dass dort eine Zahl aus einer Schleife steht
     * und keine Eingabe, macht die Zeichenkette unbedenklich.
     *
     * Die Gegenrichtung ist {@see self::fromRowSums()}.
     *
     * @param  string  $column  Die Spalte, in der die Verteilung steht.
     * @return list<string>
     */
    public static function sumExpressions(string $column = 'duration_histogram'): array
    {
        $expressions = [];

        for ($bucket = 0; $bucket <= self::MAX_BUCKET; $bucket++) {
            $expressions[] = sprintf(
                'sum(coalesce(json_extract(%s, \'$."%d"\'), 0)) as bucket_%d',
                $column,
                $bucket,
                $bucket,
            );
        }

        return $expressions;
    }

    /**
     * Baut aus den Klassensummen einer Ergebniszeile wieder eine Verteilung.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRowSums(array $row): self
    {
        $buckets = [];

        for ($bucket = 0; $bucket <= self::MAX_BUCKET; $bucket++) {
            // Als Zeichenkette, wenn MySQL antwortet, und als Zahl bei SQLite —
            // die Summe einer Spalte kommt je nach Treiber verschieden zurück.
            $count = (int) ($row['bucket_'.$bucket] ?? 0);

            if ($count > 0) {
                $buckets[$bucket] = $count;
            }
        }

        return self::fromStored($buckets);
    }

    public function count(): int
    {
        return array_sum($this->buckets);
    }

    public function isEmpty(): bool
    {
        return $this->buckets === [];
    }

    /**
     * Die Form, in der die Verteilung in der Spalte steht: nach Klasse sortiert,
     * damit zwei gleiche Verteilungen auch gleich aussehen (Vergleiche in Tests,
     * lesbare Datensätze).
     *
     * @return array<int, int>
     */
    public function toArray(): array
    {
        $buckets = $this->buckets;
        ksort($buckets);

        return $buckets;
    }
}
