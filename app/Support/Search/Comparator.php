<?php

namespace App\Support\Search;

/**
 * Der Vergleich vor einem Wert — `timesSeen:>100`, `firstSeen:<=2026-03-01`.
 *
 * `Equals` ist der Fall ohne Zeichen und trotzdem kein Sonderfall: er ist die
 * Voreinstellung und wird von den Feldern verschieden ausgelegt. Bei einer Zahl
 * heißt er „genau so oft", bei einem Datum „an diesem Tag" — und ein
 * ausdrücklich geschriebenes `=` gibt es deshalb bewusst nicht: es sähe aus wie
 * dasselbe und wäre bei Tagen etwas anderes.
 */
enum Comparator: string
{
    case Equals = '';

    case GreaterThan = '>';

    case GreaterOrEqual = '>=';

    case LessThan = '<';

    case LessOrEqual = '<=';

    /**
     * Die Zeichen, in der Reihenfolge, in der sie erkannt werden müssen —
     * `>=` vor `>`, sonst bliebe das Gleichheitszeichen im Wert stehen.
     *
     * @return list<self>
     */
    public static function prefixes(): array
    {
        return [self::GreaterOrEqual, self::LessOrEqual, self::GreaterThan, self::LessThan];
    }

    /**
     * Der Operator für die Datenbank.
     */
    public function sql(): string
    {
        return $this === self::Equals ? '=' : $this->value;
    }
}
