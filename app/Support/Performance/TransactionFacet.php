<?php

namespace App\Support\Performance;

/**
 * Die Aufschlüsselung der Antwortzeiten nach einem Merkmal — Version, Umgebung,
 * Plattform.
 *
 * Der Zweck ist die Frage „liegt es an allen oder nur an einem?". Ein p95 von
 * vier Sekunden über alle Aufrufe hinweg ist eine andere Auskunft, wenn er sich
 * aus 200 ms überall und 12 Sekunden in genau einer Version zusammensetzt — und
 * genau das ist der Regelfall, wenn etwas kaputtgegangen ist.
 *
 * Deshalb steht neben jedem Wert nicht nur seine Zahl, sondern die Einordnung:
 * ein Wert gilt als **auffällig**, wenn sein p95 deutlich über dem der übrigen
 * liegt. Verglichen wird gegen die **anderen** Werte und nicht gegen das Ganze:
 * stellt eine Version 90 % des Verkehrs, zieht sie den Gesamtwert zu sich
 * heran, und ein Vergleich damit fände nie etwas.
 */
final class TransactionFacet
{
    /**
     * Ab dem Wievielfachen des Vergleichswerts ein Wert auffällt.
     *
     * Der Faktor 1,5 ist eine Abwägung: darunter meldet die Seite die übliche
     * Streuung zwischen zwei Servern als Befund, darüber übersieht sie eine
     * Verdopplung. Er wirkt erst ab {@see self::MIN_MEASUREMENTS} Messungen —
     * ohne diese Untergrenze wäre der langsamste einzelne Aufruf einer selten
     * benutzten Version jedes Mal ein „Befund".
     */
    public const OUTLIER_FACTOR = 1.5;

    /**
     * So viele Messungen braucht ein Wert, damit er auffallen darf.
     */
    public const MIN_MEASUREMENTS = 5;

    /**
     * @param  string  $key  Der Name des Merkmals (`release`, `environment`, `platform`)
     * @param  list<TransactionFacetValue>  $values  Absteigend nach Häufigkeit
     */
    private function __construct(
        public readonly string $key,
        public readonly array $values,
    ) {}

    /**
     * Baut die Aufschlüsselung aus den Verteilungen je Wert.
     *
     * @param  array<string, DurationHistogram>  $histograms  Wert → Verteilung
     */
    public static function build(string $key, array $histograms): self
    {
        $values = [];

        foreach ($histograms as $value => $histogram) {
            $values[] = [
                'value' => (string) $value,
                'count' => $histogram->count(),
                'p95Us' => $histogram->percentile(0.95),
            ];
        }

        // Häufigstes zuerst, bei Gleichstand alphabetisch — damit derselbe
        // Datenbestand dieselbe Reihenfolge ergibt und nicht die der Schlüssel
        // eines Feldes.
        usort($values, fn (array $a, array $b): int => [$b['count'], $a['value']] <=> [$a['count'], $b['value']]);

        $rows = [];

        foreach ($values as $index => $value) {
            $rows[] = new TransactionFacetValue(
                value: $value['value'],
                count: $value['count'],
                p95Us: $value['p95Us'],
                outlier: self::isOutlier($value, $values, $index),
            );
        }

        return new self($key, $rows);
    }

    /**
     * Liegt dieser Wert deutlich über den übrigen?
     *
     * Der Vergleichswert ist der **Median** der p95 aller anderen Werte. Der
     * Mittelwert wäre die naheliegende Wahl und die falsche: er wird von genau
     * dem Ausreißer hochgezogen, der gefunden werden soll, und ab zwei
     * Ausreißern fällt keiner mehr auf.
     *
     * @param  array{value: string, count: int, p95Us: int|null}  $value
     * @param  list<array{value: string, count: int, p95Us: int|null}>  $all
     */
    private static function isOutlier(array $value, array $all, int $index): bool
    {
        if ($value['p95Us'] === null || $value['count'] < self::MIN_MEASUREMENTS) {
            return false;
        }

        $others = [];

        foreach ($all as $position => $other) {
            if ($position !== $index && $other['p95Us'] !== null) {
                $others[] = $other['p95Us'];
            }
        }

        // Ein einzelner Wert ist nie auffällig: es gibt nichts, wovon er
        // abweichen könnte. „Alles ist langsam" beantwortet die Übersicht.
        if ($others === []) {
            return false;
        }

        sort($others);

        $middle = (int) floor((count($others) - 1) / 2);
        $reference = count($others) % 2 === 0
            ? ($others[$middle] + $others[$middle + 1]) / 2
            : $others[$middle];

        return $value['p95Us'] >= $reference * self::OUTLIER_FACTOR;
    }

    /**
     * @return array{key: string, values: list<array{value: string, count: int, p95Us: int|null, outlier: bool}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'values' => array_map(
                fn (TransactionFacetValue $value): array => $value->toArray(),
                $this->values,
            ),
        ];
    }
}
