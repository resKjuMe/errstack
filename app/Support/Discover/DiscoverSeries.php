<?php

namespace App\Support\Discover;

/**
 * Eine Zeitreihe: dieselbe Frage wie die Tabelle, nur über die Zeit aufgeteilt.
 *
 * **Die Linien sind die Gruppen der Tabelle** und werden nicht neu bestimmt. Der
 * Motor liest zuerst die Rangliste und fragt dann die Reihe für genau diese
 * Gruppen ab — nur so ergibt die Summe einer Linie dieselbe Zahl, die in der
 * Tabelle steht. Würde die Reihe eigenständig gruppieren, könnte in ihr ein Browser
 * auftauchen, den die Tabelle nicht zeigt, und die beiden Ansichten derselben Frage
 * widersprächen sich.
 *
 * **Lücken sind gefüllt und nicht weggelassen.** Eine Reihe, die nur die Stunden
 * enthält, in denen etwas passiert ist, zeichnet eine Lücke als Sprung. Gefüllt wird
 * dabei nach der Rechenart: eine Anzahl ist bei null Zeilen `0`, ein Perzentil und
 * eine Quote sind `null` — aus nichts folgt keine Antwortzeit.
 */
final class DiscoverSeries
{
    /**
     * @param  list<SeriesGroup>  $groups
     * @param  list<string>  $groupBy
     * @param  list<string>  $aliases
     * @param  list<string>  $unavailable
     * @param  array{message: string, position: int, excerpt: string}|null  $searchError
     */
    public function __construct(
        public readonly array $groups,
        public readonly Interval $interval,
        public readonly array $groupBy,
        public readonly array $aliases,
        public readonly bool $truncated = false,
        public readonly array $unavailable = [],
        public readonly ?array $searchError = null,
        public readonly bool $cached = false,
    ) {}

    /**
     * Die einzige Linie — die Form ohne Gruppierung.
     */
    public function first(): ?SeriesGroup
    {
        return $this->groups[0] ?? null;
    }

    public function withCached(bool $cached): self
    {
        return new self(
            $this->groups,
            $this->interval,
            $this->groupBy,
            $this->aliases,
            $this->truncated,
            $this->unavailable,
            $this->searchError,
            $cached,
        );
    }

    /**
     * @return array{
     *     groups: list<array{groups: array<string, string|null>, points: list<array{at: string, values: array<string, float|null>}>}>,
     *     interval: string,
     *     group_by: list<string>,
     *     aliases: list<string>,
     *     truncated: bool,
     *     unavailable: list<string>,
     *     search_error: array{message: string, position: int, excerpt: string}|null,
     *     cached: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'groups' => array_map(static fn (SeriesGroup $group): array => $group->toArray(), $this->groups),
            'interval' => $this->interval->key,
            'group_by' => $this->groupBy,
            'aliases' => $this->aliases,
            'truncated' => $this->truncated,
            'unavailable' => $this->unavailable,
            'search_error' => $this->searchError,
            'cached' => $this->cached,
        ];
    }
}
