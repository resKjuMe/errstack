<?php

namespace App\Support\Discover;

/**
 * Das Ergebnis einer Auswertung — samt dem, was der Motor **nicht** konnte.
 *
 * Die drei Nebenangaben sind der Grund für diese Klasse und nicht bloß eine Liste
 * von Zeilen:
 *
 *   - {@see self::$truncated} — es gab mehr Gruppen als angefordert. Ohne diese
 *     Angabe wäre eine abgeschnittene Rangliste von einer vollständigen nicht zu
 *     unterscheiden, und „die zehn häufigsten" hieße manchmal „alle zehn, die es
 *     gibt".
 *   - {@see self::$searchError} — die Suchbedingung war nicht zu deuten. Die
 *     Auswertung steht dann **ungefiltert** da und sagt, an welcher Stelle es
 *     klemmt; die Alternative wäre eine leere Seite mit einer Fehlermeldung, aus
 *     der man nur durch Löschen der Adresszeile herausfindet.
 *   - {@see self::$unavailable} — Felder, die es in der Sprache gibt und in dieser
 *     Quelle nicht. Sie haben nichts eingeschränkt, und das gehört gesagt.
 *
 * {@see self::$cached} ist keine Nebenangabe für den Betrachter, sondern die
 * Antwort auf „warum sehe ich die Änderung noch nicht": eine Zahl aus dem
 * Zwischenspeicher ist bis zu einem Raster alt.
 */
final class DiscoverResult
{
    /**
     * @param  list<DiscoverRow>  $rows
     * @param  list<string>  $groupBy
     * @param  list<string>  $aliases
     * @param  list<string>  $unavailable
     * @param  array{message: string, position: int, excerpt: string}|null  $searchError
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $groupBy,
        public readonly array $aliases,
        public readonly bool $truncated = false,
        public readonly array $unavailable = [],
        public readonly ?array $searchError = null,
        public readonly bool $cached = false,
    ) {}

    /**
     * Die erste Zeile — die einzige, wenn nicht gruppiert wurde.
     */
    public function first(): ?DiscoverRow
    {
        return $this->rows[0] ?? null;
    }

    public function withCached(bool $cached): self
    {
        return new self(
            $this->rows,
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
     *     rows: list<array{groups: array<string, string|null>, values: array<string, float|null>}>,
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
            'rows' => array_map(static fn (DiscoverRow $row): array => $row->toArray(), $this->rows),
            'group_by' => $this->groupBy,
            'aliases' => $this->aliases,
            'truncated' => $this->truncated,
            'unavailable' => $this->unavailable,
            'search_error' => $this->searchError,
            'cached' => $this->cached,
        ];
    }
}
