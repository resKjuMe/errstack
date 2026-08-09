<?php

namespace App\Support\Discover;

/**
 * Ein Feld einer Datenquelle: wie es heißt, was darin steht und wo es liegt.
 *
 * **Zwei Formen derselben Stelle, und beide werden gebraucht.** `$column` ist der
 * Pfad in der Schreibweise des Abfrage-Baumeisters (`environment`,
 * `contexts->browser->name`) — damit lässt sich `where()` bauen, und der Treiber
 * übersetzt den JSON-Zugriff selbst. `$sql` ist derselbe Zugriff als fertiges
 * Bruchstück, wie es eine Gruppierung und eine Rechenart brauchen, die keinen
 * Baumeister vor sich haben.
 *
 * Bei zusammengesetzten Feldern (`browser` aus Name und Fassung, `url` ohne
 * Abfrageteil) gibt es keinen Pfad, sondern nur den Ausdruck: `$column` ist dann
 * `null`, und die Suche vergleicht über das Bruchstück. Das ist der Grund, warum
 * beides hier steht und nicht eines aus dem anderen gerechnet wird.
 */
final class FieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly ?string $column,
        public readonly string $sql,
        public readonly bool $groupable = true,
        public readonly bool $aggregatable = false,
    ) {}
}
