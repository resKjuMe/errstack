<?php

namespace App\Support\Discover;

/**
 * Eine Zeile einer Auswertung: die Werte der Gruppierung und die Kennzahlen dazu.
 *
 * Getrennt gehalten und nicht in einer Abbildung vermischt, weil die beiden
 * verschieden zu behandeln sind: ein Gruppenwert ist Text und kann `null` sein
 * („dieses Merkmal fehlt"), eine Kennzahl ist eine Zahl und `null` heißt dort
 * „nicht zu beantworten". Eine gemeinsame Abbildung würde die Oberfläche zwingen,
 * das an den Namen zu erraten — und ein Merkmal, das zufällig `count` heißt, wäre
 * eine Kennzahl.
 */
final class DiscoverRow
{
    /**
     * @param  array<string, string|null>  $groups
     * @param  array<string, float|null>  $values
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $values,
    ) {}

    public function value(string $alias): ?float
    {
        return $this->values[$alias] ?? null;
    }

    /**
     * @return array{groups: array<string, string|null>, values: array<string, float|null>}
     */
    public function toArray(): array
    {
        return ['groups' => $this->groups, 'values' => $this->values];
    }
}
