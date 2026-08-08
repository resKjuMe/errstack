<?php

namespace App\Support\Performance\Vitals;

use App\Enums\WebVital;

/**
 * Die Aufschlüsselung eines Messwerts nach einem Merkmal — Gerät, Browser,
 * Land.
 *
 * Der Zweck ist die Frage „liegt es an allen oder nur an einem?". Ein LCP von
 * vier Sekunden über alle Ladevorgänge hinweg ist eine völlig andere Auskunft,
 * wenn es sich aus 1,5 Sekunden auf dem Rechner und neun Sekunden auf dem Handy
 * zusammensetzt — und das ist bei Browser-Messwerten der Regelfall und nicht die
 * Ausnahme. Genau deshalb ist die Aufschlüsselung hier keine Zusatzfunktion,
 * sondern der Grund, warum die Seite überhaupt weiterhilft.
 *
 * Anders als bei den Antwortzeiten (PF3) wird **nicht** gegen die übrigen Werte
 * verglichen, um Auffälliges zu markieren. Das ist hier nicht nötig: es gibt
 * eine feste Schwelle, und „schlecht" ist keine Frage des Vergleichs mit den
 * anderen Geräten, sondern eine der Spezifikation. Ein Balken, der überall rot
 * ist, ist eine richtige Auskunft — der Vergleich mit dem Durchschnitt würde
 * genau dort nichts mehr finden.
 */
final class VitalFacet
{
    /**
     * So viele Werte zeigt eine Aufschlüsselung.
     *
     * Danach kommt der lange Schwanz aus einzelnen Geräten und Ländern, und die
     * Frage „woran liegt es" ist nach den ersten Zeilen beantwortet.
     */
    public const VALUE_LIMIT = 8;

    /**
     * Ab so vielen Messungen wird ein Wert überhaupt gezeigt.
     *
     * Ein einzelner Ladevorgang aus Neuseeland ist keine Auskunft über
     * Neuseeland. Ohne diese Grenze bestünde die Liste aus lauter Einzelfällen
     * mit einem Perzentil, das ihr eigener Wert ist.
     */
    public const MIN_MEASUREMENTS = 3;

    /**
     * @param  string  $key  Der Name des Merkmals (`device`, `browser`, `country`)
     * @param  list<VitalFacetValue>  $values  Absteigend nach Häufigkeit
     */
    private function __construct(
        public readonly string $key,
        public readonly array $values,
        public readonly bool $truncated,
    ) {}

    /**
     * Baut die Aufschlüsselung aus den Werten je Merkmalsausprägung.
     *
     * Die Bewertung entsteht hier **aus dem Perzentil** und nicht aus gezählten
     * Klassen — anders als bei der Zusammenfassung ({@see VitalSummary}). Der
     * Grund ist die Quelle: eine Aufschlüsselung liest die Einzelmessungen einer
     * Stichprobe, und dort steht der genaue Wert jeder Messung. Das Perzentil
     * ist damit exakt und keine Näherung; es gibt nichts zurechtzurücken.
     *
     * @param  array<string, list<int>>  $values  Ausprägung → gemessene Werte in
     *                                            Millionsteln.
     */
    public static function build(string $key, WebVital $vital, array $values): self
    {
        $rows = [];

        foreach ($values as $value => $measurements) {
            if (count($measurements) < self::MIN_MEASUREMENTS) {
                continue;
            }

            sort($measurements);

            $percentile = self::percentileOf($measurements, WebVital::PERCENTILE);

            $rows[] = new VitalFacetValue(
                value: (string) $value,
                count: count($measurements),
                percentileValue: $percentile,
                rating: $vital->rate($percentile),
            );
        }

        // Häufigstes zuerst, bei Gleichstand alphabetisch — damit derselbe
        // Datenbestand dieselbe Reihenfolge ergibt und nicht die der Schlüssel
        // eines Feldes.
        usort($rows, static fn (VitalFacetValue $a, VitalFacetValue $b): int => [$b->count, $a->value] <=> [$a->count, $b->value]);

        return new self($key, array_slice($rows, 0, self::VALUE_LIMIT), count($rows) > self::VALUE_LIMIT);
    }

    /**
     * Der Wert an einer Perzentil-Stelle einer sortierten Liste.
     *
     * Aufgerundet, damit dieselbe Stelle gemeint ist wie überall sonst: bei vier
     * Messungen ist das p75 die dritte.
     *
     * @param  list<int>  $sorted  Aufsteigend
     */
    private static function percentileOf(array $sorted, float $percentile): int
    {
        $total = count($sorted);
        $rank = (int) max(1, ceil($percentile * $total));

        return $sorted[min($rank, $total) - 1];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => __('web_vitals.facets.'.$this->key),
            'truncated' => $this->truncated,
            'values' => array_map(
                static fn (VitalFacetValue $value): array => $value->toArray(),
                $this->values,
            ),
        ];
    }
}
