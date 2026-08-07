<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Die HTTP-Anfrage, bei der der Fehler auftrat.
 *
 * Für einen Web-Fehler die zweitwichtigste Auskunft nach dem Stacktrace: der
 * Weg sagt, welche Stelle betroffen ist, die Kopfzeilen sagen, welcher Client
 * es war, und der Rumpf sagt, womit es schieflief.
 *
 * Zugleich der Abschnitt, in dem am ehesten steht, was nicht gespeichert werden
 * darf — Sitzungsnachweise in den Keksen, ein Anmeldenachweis in
 * `Authorization`, eine Kartennummer im Rumpf. Entfernt wird das im Scrubbing
 * (I7), und zwar **vor** dieser Stelle in der Kette. Hier wird deshalb nichts
 * verheimlicht, aber auch nichts hinzuerfunden: was ankommt, kommt in die
 * vorgesehenen Fächer, damit der Schritt davor überhaupt weiß, wo er suchen
 * muss.
 */
final class Request
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $request, string $path): ?array
    {
        $request = $this->sanitizer->map($request, $path);

        if ($request === null) {
            return null;
        }

        $normalized = [];

        $url = $this->sanitizer->text($request['url'] ?? null, $path.'.url', 2_048);

        if ($url !== null) {
            $normalized['url'] = $url;
        }

        $method = $this->sanitizer->text($request['method'] ?? null, $path.'.method', 20);

        if ($method !== null) {
            // Verfahren sind in HTTP großgeschrieben. Ohne diese eine Zeile
            // stünden `get` und `GET` als zwei Werte in jeder Auswertung.
            $normalized['method'] = strtoupper($method);
        }

        foreach (['fragment', 'protocol', 'inferred_content_type', 'api_target'] as $field) {
            $value = $this->sanitizer->text($request[$field] ?? null, $path.'.'.$field, 200);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $query = $this->queryString($request['query_string'] ?? null, $path.'.query_string');

        if ($query !== null) {
            $normalized['query_string'] = $query;
        }

        foreach (['headers', 'cookies', 'env'] as $field) {
            $entries = $this->sanitizer->entries($request[$field] ?? null, $path.'.'.$field);

            if ($entries !== []) {
                $normalized[$field] = $entries;
            }
        }

        // Der Rumpf ist alles: JSON, Formularfelder, roher Text. Er bleibt
        // deshalb frei geformt und wird nur in Tiefe und Länge begrenzt.
        if (array_key_exists('data', $request) && $request['data'] !== null) {
            $data = $this->sanitizer->freeform($request['data'], $path.'.data');

            if ($data !== null && $data !== []) {
                $normalized['data'] = $data;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Die Abfragezeichenkette.
     *
     * Sie kommt in drei Formen: als Zeichenkette (`a=1&b=2`), als Objekt und
     * als Liste von Paaren. Vereinheitlicht wird auf das Objekt — das ist die
     * Form, in der sich einzelne Werte ansehen und ab I7 gezielt entfernen
     * lassen; in einer Zeichenkette müsste dafür jeder Schritt selbst zerlegen.
     *
     * @return array<string, string>|null
     */
    private function queryString(mixed $query, string $path): ?array
    {
        if ($query === null) {
            return null;
        }

        if (is_string($query)) {
            $text = $this->sanitizer->text($query, $path, 4_096);

            if ($text === null) {
                return null;
            }

            /** @var array<string, mixed> $parsed */
            $parsed = [];
            parse_str(ltrim($text, '?'), $parsed);

            return $parsed === [] ? null : $this->sanitizer->entries($parsed, $path);
        }

        if (is_array($query) && array_is_list($query)) {
            $pairs = [];

            foreach ($query as $pair) {
                // Die Listenform ist eine Liste von Paaren: [["a", "1"], …].
                if (is_array($pair) && array_is_list($pair) && count($pair) === 2 && is_scalar($pair[0])) {
                    $pairs[(string) $pair[0]] = $pair[1];
                }
            }

            $query = $pairs;
        }

        $entries = $this->sanitizer->entries($query, $path);

        return $entries === [] ? null : $entries;
    }
}
