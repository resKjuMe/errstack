<?php

namespace App\Support\Search\Ast;

use App\Support\Search\Comparator;
use App\Support\Search\FieldResolver;
use Closure;

/**
 * Eine einzelne Aussage über ein Feld: `is:unresolved`, `timesSeen:>100`,
 * `browser:Chrome*`.
 *
 * Sie trägt alles mit, was der Auflöser braucht, um eine **verständliche**
 * Meldung zu schreiben: den Feldnamen so, wie er getippt wurde (nicht
 * kleingeschrieben), die Stelle des Wertes im Text, und ob der Wert in
 * Anführungszeichen stand. Das letzte entscheidet über Platzhalter und
 * Vergleiche — in Anführungszeichen bedeutet ein Stern einen Stern.
 */
final class Condition implements Expression
{
    public function __construct(
        public readonly string $field,
        public readonly Comparator $comparator,
        public readonly string $value,
        public readonly bool $quoted,
        public readonly int $position,
        public readonly int $valuePosition,
    ) {}

    /**
     * Der Feldname zum Vergleichen — Groß- und Kleinschreibung ist beim
     * Schlüssel gleichgültig, `firstSeen` und `firstseen` sind dasselbe Feld.
     */
    public function key(): string
    {
        return mb_strtolower($this->field);
    }

    /**
     * Enthält der Wert einen Platzhalter, der auch als solcher gemeint ist?
     */
    public function hasWildcard(): bool
    {
        return ! $this->quoted && str_contains($this->value, '*');
    }

    public function compile(FieldResolver $fields): ?Closure
    {
        return $fields->condition($this);
    }
}
