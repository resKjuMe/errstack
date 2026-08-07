<?php

namespace App\Support\Ingest;

use stdClass;

/**
 * Liest JSON, das ein **Objekt** sein muss, als Feld-Baum.
 *
 * Warum das nicht `json_decode(…, true)` allein tut: mit `true` wird sowohl
 * `{}` als auch `[]` zu `[]`. Genau diese Unterscheidung ist hier aber die
 * ganze Prüfung — ein Envelope-Kopf darf leer sein (`{}` kommt im Feld
 * laufend vor), eine Liste ist nie ein Kopf. Nachträglich mit
 * `array_is_list()` zu prüfen, wirft deshalb das leere Objekt weg und mit ihm
 * jeden Envelope ohne Kopfdaten.
 *
 * Also erst als Objekt lesen, um zu entscheiden, und dann als Feld-Baum, um
 * damit zu arbeiten. Die Zeilen sind kurz; das zweite Lesen fällt nicht ins
 * Gewicht.
 */
final class JsonObject
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $json): ?array
    {
        if (! json_decode($json) instanceof stdClass) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
