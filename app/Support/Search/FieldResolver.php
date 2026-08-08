<?php

namespace App\Support\Search;

use App\Support\Search\Ast\Condition;
use App\Support\Search\Ast\FreeText;
use Closure;

/**
 * Was ein Feld in einer bestimmten Welt bedeutet.
 *
 * Die Suchsprache endet an dieser Grenze: bis hierher geht es um Klammern,
 * Verneinung und Verknüpfung, ab hier um Spalten und Tabellen. Die Fehlerliste
 * bringt ihren Auflöser mit (`IssueFields`), die freien Auswertungen (D1) werden
 * ihren eigenen mitbringen — die Sprache bleibt dieselbe, und damit auch die
 * Links, die jemand gespeichert hat.
 *
 * **`null` heißt „schränkt nichts ein".** Ein Auflöser gibt das zurück, wenn er
 * ein Feld zwar kennt, die Daten dazu aber noch nicht existieren; welche das
 * waren, meldet er über {@see self::unavailable()} zurück, damit die Oberfläche
 * es sagen kann. Ein Feld, das er **gar nicht** kennt, ist dagegen kein
 * Sonderfall: es ist ein Merkmal, und `browser:Chrome` soll ohne Anmeldung
 * funktionieren.
 */
interface FieldResolver
{
    /**
     * Übersetzt eine Aussage über ein Feld.
     *
     * @return (Closure(\Illuminate\Database\Eloquent\Builder<*>): void)|null
     *
     * @throws SearchSyntaxException bei einem Wert, den dieses Feld nicht kennt
     */
    public function condition(Condition $condition): ?Closure;

    /**
     * Übersetzt einen Begriff ohne Feld.
     *
     * @return (Closure(\Illuminate\Database\Eloquent\Builder<*>): void)|null
     */
    public function freeText(FreeText $text): ?Closure;

    /**
     * Die Felder, die zwar zur Sprache gehören, hier aber (noch) nichts
     * einschränken — in der Schreibweise, in der sie getippt wurden.
     *
     * @return list<string>
     */
    public function unavailable(): array;
}
