<?php

namespace App\Support\Discover;

/**
 * Eine Linie einer Zeitreihe: wofür sie steht und ihre Stützstellen.
 *
 * Ohne Gruppierung gibt es genau eine Linie mit leeren Gruppenwerten — derselbe
 * Gegenstand, damit die Oberfläche nicht zwei Formen von Zeitreihe kennen muss.
 */
final class SeriesGroup
{
    /**
     * @param  array<string, string|null>  $groups
     * @param  list<SeriesPoint>  $points
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $points,
    ) {}

    /**
     * Die Summe einer Kennzahl über alle Stützstellen.
     *
     * Nur bei Anzahlen eine Aussage: die Summe von Perzentilen ist keine Zahl, die
     * etwas bedeutet. Gebraucht wird sie dort, wo eine Reihe und eine Tabelle
     * derselben Frage nebeneinanderstehen und dieselbe Gesamtzahl zeigen müssen.
     */
    public function total(string $alias): ?float
    {
        $total = null;

        foreach ($this->points as $point) {
            $value = $point->values[$alias] ?? null;

            if ($value !== null) {
                $total = ($total ?? 0.0) + $value;
            }
        }

        return $total;
    }

    /**
     * @return array{groups: array<string, string|null>, points: list<array{at: string, values: array<string, float|null>}>}
     */
    public function toArray(): array
    {
        return [
            'groups' => $this->groups,
            'points' => array_map(static fn (SeriesPoint $point): array => $point->toArray(), $this->points),
        ];
    }
}
