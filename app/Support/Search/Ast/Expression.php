<?php

namespace App\Support\Search\Ast;

use App\Support\Search\FieldResolver;
use App\Support\Search\SearchSyntaxException;
use Closure;

/**
 * Ein Knoten des Ausdrucksbaums.
 *
 * Der Baum kennt die Datenbank nicht. Er sagt, **was** gefragt wurde — `a und
 * (b oder nicht c)` —, und ein {@see FieldResolver} sagt, was ein einzelnes Feld
 * in seiner Welt bedeutet. Das ist die Trennung, an der die freien Auswertungen
 * (D1) später ansetzen: dieselbe Sprache, dieselbe Zerlegung, ein anderer
 * Auflöser.
 *
 * **Übersetzt wird einmal, nicht bei jeder Abfrage.** `compile()` liefert eine
 * fertige Einschränkung oder `null` — und `null` heißt „schränkt nichts ein".
 * Der Fall ist nicht theoretisch: `bookmarks:mir` ist eine gültige Frage, für
 * die es die Daten (noch) nicht gibt, und dann ist die ehrliche Antwort eine
 * ungefilterte Liste samt Hinweis und keine leere.
 */
interface Expression
{
    /**
     * Übersetzt den Knoten in eine Einschränkung — oder `null`, wenn er keine
     * ergibt.
     *
     * @return (Closure(\Illuminate\Database\Eloquent\Builder<*>): void)|null
     *
     * @throws SearchSyntaxException
     */
    public function compile(FieldResolver $fields): ?Closure;
}
