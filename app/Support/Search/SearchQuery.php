<?php

namespace App\Support\Search;

/**
 * Die andere Richtung: aus Feld und Wert wird eine Sucheingabe.
 *
 * Gebraucht überall dort, wo eine Einschränkung nicht getippt, sondern
 * **geklickt** wird — der Balken in einer Merkmal-Verteilung, die Version in der
 * Auslieferungsliste. Der Klick soll denselben Ausdruck erzeugen, den jemand
 * sonst geschrieben hätte: dann steht er im Suchfeld, lässt sich dort ändern und
 * ergänzen, und der Link darauf ist derselbe Link.
 *
 * Ohne diese Stelle gäbe es zwei Schreibweisen für dieselbe Aussage — ein
 * eigener Parameter für den Klick und die Suchsprache fürs Tippen — und damit
 * zwei Wege, dieselbe Liste zu meinen.
 */
final class SearchQuery
{
    /**
     * Zeichen, die einen Wert in Anführungszeichen zwingen: sie bedeuten der
     * Suche etwas.
     */
    private const SPECIAL = " \t\n\"()!*:";

    /**
     * `browser:"Chrome 124"`.
     */
    public static function term(string $field, string $value): string
    {
        return $field.':'.self::quote($value);
    }

    /**
     * Ein Wert, wie die Suche ihn wörtlich nimmt.
     */
    public static function quote(string $value): string
    {
        $plain = $value !== ''
            && mb_strtolower($value) !== 'and'
            && mb_strtolower($value) !== 'or'
            && strpbrk($value, self::SPECIAL) === false;

        if ($plain) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
