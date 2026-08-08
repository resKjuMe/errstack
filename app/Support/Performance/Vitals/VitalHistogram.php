<?php

namespace App\Support\Performance\Vitals;

use App\Enums\VitalRating;
use App\Support\Performance\DurationHistogram;

/**
 * Die Verteilung von Web-Vitals-Werten als Häufigkeiten über festen Klassen.
 *
 * Der Zweck ist derselbe wie bei {@see DurationHistogram}: Perzentile über
 * beliebige Zeiträume, weil sich Verteilungen addieren lassen und fertige
 * Kennzahlen nicht. Warum es die Klasse trotzdem zweimal gibt, ist eine Frage
 * der **Auflösung**.
 *
 * Die Klassen der Antwortzeiten verdoppeln sich: von 1,6 s springt die nächste
 * Grenze auf 3,3 s. Für die Frage „ist dieser Endpunkt langsam geworden" ist das
 * genau richtig. Für einen Web Vital ist es unbrauchbar — die Schwelle für ein
 * gutes LCP liegt bei 2,5 s und damit **mitten in dieser Klasse**. Ein
 * angezeigtes p75 wäre um bis zu 100 % daneben, und eine Anzeige, die 2,4 s als
 * 3,3 s ausweist, macht aus einer guten Seite eine schlechte.
 *
 * Deshalb hier **vier Klassen je Verdopplung**. Der größte Fehler eines
 * Perzentils sinkt damit von 100 % auf 19 %, und mit der Mitte der Klasse als
 * Schätzwert ({@see percentile()}) auf ±9 %. Der Preis sind 65 statt 31 Klassen —
 * mehr Spalten in der Summierung, aber dieselbe eine Abfrage.
 *
 * **Die Bewertung hängt nicht an dieser Genauigkeit.** Ob eine Seite als gut
 * oder schlecht gilt, wird beim Eintreffen jeder Messung mit ihrem genauen Wert
 * entschieden und als Zähler abgelegt ({@see VitalRating}). Diese
 * Verteilung liefert nur die **Zahl**, die daneben steht — und wird an der
 * exakten Klasse noch zurechtgerückt ({@see VitalSummary}).
 *
 * Gerechnet wird durchgehend in Millionsteln: Mikrosekunden bei den Dauern,
 * Millionstel der Punktzahl beim Verschiebungswert.
 */
final class VitalHistogram
{
    /**
     * Obergrenze der ersten Klasse in Millionsteln.
     *
     * Alles darunter (1 ms, bzw. ein CLS von 0,001) landet zusammen in Klasse 0.
     * Feiner aufzulösen hätte keinen Wert: kein Web Vital hat eine Schwelle in
     * dieser Größenordnung, und ein TTFB von 0,4 ms ist keine andere Auskunft
     * als einer von 0,8 ms.
     */
    public const BASE = 1_000;

    /**
     * Wie viele Klassen auf eine Verdopplung entfallen.
     */
    public const STEPS = 4;

    /**
     * Höchste Klasse. Klasse 64 beginnt bei 1 ms · 2^16, also rund 65 Sekunden —
     * was länger braucht, ist kein Ladeerlebnis mehr, sondern ein Abbruch, und
     * wird in der letzten Klasse gesammelt.
     */
    public const MAX_BUCKET = 64;

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
     * Nachsichtig gegenüber allem, was nicht passt — aus demselben Grund wie bei
     * den Antwortzeiten: die Spalte ist ein Feld-Baum, und eine kaputte Zelle
     * darf die laufende Aufnahme nicht anhalten.
     *
     * @param  mixed  $stored  Der Inhalt der Spalte `value_histogram`.
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
    public function add(int $value, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $bucket = self::bucketFor($value);

        $this->buckets[$bucket] = ($this->buckets[$bucket] ?? 0) + $count;
    }

    /**
     * Legt eine zweite Verteilung auf diese.
     */
    public function merge(self $other): void
    {
        foreach ($other->buckets as $bucket => $count) {
            $this->buckets[$bucket] = ($this->buckets[$bucket] ?? 0) + $count;
        }
    }

    /**
     * Die Klasse, in die ein Wert fällt.
     *
     * Die Umkehrung von `BASE · 2^(n/STEPS)`.
     */
    public static function bucketFor(int $value): int
    {
        if ($value <= self::BASE) {
            return 0;
        }

        $bucket = (int) floor(log($value / self::BASE, 2) * self::STEPS) + 1;

        return min($bucket, self::MAX_BUCKET);
    }

    /**
     * Untergrenze einer Klasse in Millionsteln — was die Anzeige braucht, um die
     * Verteilung als Balken zu zeichnen.
     */
    public static function lowerBound(int $bucket): int
    {
        return $bucket <= 0 ? 0 : (int) round(self::BASE * 2 ** (($bucket - 1) / self::STEPS));
    }

    /**
     * Das Perzentil einer Verteilung, oder `null`, wenn nichts gemessen wurde.
     *
     * Zurückgegeben wird die **Mitte** der Klasse, in der das Perzentil liegt,
     * und nicht ihre Obergrenze wie bei den Antwortzeiten. Der Unterschied ist
     * kein Detail: dort geht es darum, eine Antwortzeit im Zweifel eher zu hoch
     * auszuweisen, damit ein Schwellwert-Alarm nicht ausbleibt. Hier steht die
     * Zahl neben einer Bewertung — und ein durchgängig zu hoher Wert würde
     * systematisch gute Seiten schlecht aussehen lassen. Die Mitte ist der
     * Schätzwert ohne Schlagseite, mit einem Fehler von höchstens ±9 %.
     *
     * Geometrisch gemittelt und nicht arithmetisch: die Klassen wachsen
     * multiplikativ, und in einer Klasse von 2,0 s bis 2,4 s ist 2,19 s die
     * Mitte im selben Sinn, in dem die Klassengrenzen gebildet wurden.
     *
     * @param  float  $percentile  Zwischen 0 und 1 — 0.75 für das p75.
     */
    public function percentile(float $percentile): ?int
    {
        $total = $this->count();

        if ($total === 0) {
            return null;
        }

        $percentile = max(0.0, min(1.0, $percentile));

        // Aufgerundet, damit das p100 tatsächlich die letzte Messung trifft.
        $target = (int) max(1, ceil($percentile * $total));

        $seen = 0;
        $buckets = $this->buckets;
        ksort($buckets);

        foreach ($buckets as $bucket => $count) {
            $seen += $count;

            if ($seen >= $target) {
                return self::middleOf($bucket);
            }
        }

        return self::middleOf(self::MAX_BUCKET);
    }

    /**
     * Der Schätzwert für eine Klasse: ihre geometrische Mitte.
     *
     * Klasse 0 reicht von 0 bis {@see BASE} und hat keine geometrische Mitte —
     * für sie ist die halbe Obergrenze der beste Schätzwert. Die letzte Klasse
     * ist nach oben offen; dort ist ihre Untergrenze das Ehrlichste, was sich
     * sagen lässt, denn jeder darüber liegende Wert wäre geraten.
     */
    public static function middleOf(int $bucket): int
    {
        if ($bucket <= 0) {
            return (int) round(self::BASE / 2);
        }

        if ($bucket >= self::MAX_BUCKET) {
            return self::lowerBound(self::MAX_BUCKET);
        }

        return (int) round(sqrt(self::lowerBound($bucket) * self::lowerBound($bucket + 1)));
    }

    /**
     * Je Klasse eine Summe, als SQL-Ausdruck — der Weg, Verteilungen **in der
     * Datenbank** zusammenzulegen statt alle Zeilen zu laden.
     *
     * Dieselbe Mechanik wie bei den Antwortzeiten, samt der Anführungszeichen um
     * den Pfad (`$."7"`): eine reine Ziffer als Feldname ist sonst in keiner der
     * beiden Datenbanken zu deuten. Die Gegenrichtung ist {@see fromRowSums()}.
     *
     * @param  string  $column  Die Spalte, in der die Verteilung steht.
     * @return list<string>
     */
    public static function sumExpressions(string $column = 'value_histogram'): array
    {
        $expressions = [];

        for ($bucket = 0; $bucket <= self::MAX_BUCKET; $bucket++) {
            $expressions[] = sprintf(
                'sum(coalesce(json_extract(%s, \'$."%d"\'), 0)) as vital_bucket_%d',
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
            // Als Zeichenkette, wenn MySQL antwortet, und als Zahl bei SQLite.
            $count = (int) ($row['vital_bucket_'.$bucket] ?? 0);

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
     * Die Verteilung als Balken: je belegter Klasse ihre Grenzen und Häufigkeit.
     *
     * Leere Klassen zwischen zwei belegten bleiben stehen — eine Lücke ist ein
     * Befund („zwei Gruppen von Geräten") und keine Leerstelle, die man
     * zusammenschieben darf. Vor der ersten und nach der letzten belegten Klasse
     * wird dagegen abgeschnitten, sonst zeichnete jede Grafik 65 Balken, von
     * denen sechzig leer sind.
     *
     * @return list<array{from: int, to: int|null, count: int}>
     */
    public function bars(): array
    {
        if ($this->buckets === []) {
            return [];
        }

        $buckets = $this->buckets;
        ksort($buckets);

        $first = array_key_first($buckets);
        $last = array_key_last($buckets);

        $bars = [];

        for ($bucket = $first; $bucket <= $last; $bucket++) {
            $bars[] = [
                'from' => self::lowerBound($bucket),
                // Die letzte Klasse ist nach oben offen — `null` sagt das, eine
                // ausgedachte Obergrenze würde es verschweigen.
                'to' => $bucket >= self::MAX_BUCKET ? null : self::lowerBound($bucket + 1),
                'count' => $buckets[$bucket] ?? 0,
            ];
        }

        return $bars;
    }

    /**
     * Die Form, in der die Verteilung in der Spalte steht: nach Klasse sortiert,
     * damit zwei gleiche Verteilungen auch gleich aussehen.
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
