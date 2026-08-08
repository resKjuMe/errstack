<?php

namespace App\Support\Search\Ast;

use App\Support\Search\FieldResolver;
use Closure;

/**
 * Ein Begriff ohne Feld — das, was jemand tippt, wenn er den Fehler noch nicht
 * benennen kann, sondern nur ein Wort daraus kennt.
 *
 * Wo gesucht wird, entscheidet der Auflöser und nicht dieser Knoten: in der
 * Fehlerliste sind es Titel und Fehlerstelle, in einer freien Auswertung (D1)
 * wären es andere Spalten.
 */
final class FreeText implements Expression
{
    public function __construct(
        public readonly string $text,
        public readonly bool $quoted,
        public readonly int $position,
    ) {}

    public function compile(FieldResolver $fields): ?Closure
    {
        return $fields->freeText($this);
    }
}
