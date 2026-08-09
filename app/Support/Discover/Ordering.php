<?php

namespace App\Support\Discover;

/**
 * Wonach sortiert wird — nach einer Kennzahl oder nach einem Gruppierungsfeld.
 *
 * Der Bezug ist der **Name in der Ergebniszeile** (`count`, `p95_duration`,
 * `browser`) und nicht eine Spalte: eine Sortierung nach `p95(duration)` gibt es
 * in SQL nicht, weil das Perzentil aus der Verteilung gelesen wird. Der Motor
 * entscheidet daran, ob er die Datenbank sortieren lässt oder selbst sortiert
 * ({@see DiscoverEngine}); der Aufrufer schreibt beides gleich.
 */
final class Ordering
{
    private function __construct(
        public readonly string $key,
        public readonly bool $descending,
    ) {}

    public static function desc(string $key): self
    {
        return new self($key, true);
    }

    public static function asc(string $key): self
    {
        return new self($key, false);
    }

    /**
     * Liest `-count` als „absteigend nach count" — die Schreibweise, in der eine
     * Sortierung in einer Adresszeile steht.
     */
    public static function parse(string $key): self
    {
        $key = trim($key);

        return str_starts_with($key, '-')
            ? self::desc(mb_substr($key, 1))
            : self::asc($key);
    }

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }
}
