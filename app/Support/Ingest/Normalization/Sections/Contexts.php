<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Die Umgebung, in der der Fehler auftrat: Betriebssystem, Browser, Laufzeit,
 * Gerät — und die Spur, die die Meldung mit einer Transaktion verbindet.
 *
 * Der Abschnitt ist von Sentry ausdrücklich **offen** angelegt: die Namen der
 * Fächer sind frei wählbar, und jedes Fach nennt in `type`, wie es zu lesen
 * ist. Genau das wird hier bewahrt. Eine feste Liste bekannter Fächer wäre
 * bequemer, würde aber alles wegwerfen, was ein SDK an eigenen Angaben
 * mitschickt — und das ist bei jedem SDK etwas anderes.
 *
 * Eine Ausnahme gibt es: `trace`. Daran hängt ab P5 die Verbindung zwischen
 * einem Fehler und der Transaktion, in der er auftrat. Dieses eine Fach
 * bekommt deshalb feste Felder, damit später niemand raten muss, wie eine
 * Spur-Kennung geschrieben war.
 */
final class Contexts
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $contexts, string $path): ?array
    {
        $contexts = $this->sanitizer->map($contexts, $path);

        if ($contexts === null) {
            return null;
        }

        $normalized = [];

        foreach ($contexts as $name => $context) {
            $key = $this->sanitizer->text($name, $path, 200);

            if ($key === null) {
                continue;
            }

            $entry = $key === 'trace'
                ? $this->trace($context, $path.'.trace')
                : $this->context($key, $context, $path.'.'.$key);

            if ($entry !== null) {
                $normalized[$key] = $entry;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Ein beliebiges Fach.
     *
     * Der Name des Fachs dient als Vorgabewert für `type`, weil beide fast
     * immer gleich sind und die SDKs `type` deshalb gern weglassen. Ohne den
     * Vorgabewert stünde in der Anzeige „unbekannt" neben Angaben, deren Art
     * im Schlüssel danebensteht.
     *
     * @return array<string, mixed>|null
     */
    private function context(string $name, mixed $context, string $path): ?array
    {
        $context = $this->sanitizer->map($context, $path);

        if ($context === null) {
            return null;
        }

        $normalized = $this->sanitizer->freeform($context, $path);

        if (! is_array($normalized) || $normalized === []) {
            return null;
        }

        if (! isset($normalized['type']) || ! is_string($normalized['type'])) {
            $normalized['type'] = $name;
        }

        return $normalized;
    }

    /**
     * Die Spur, an der Fehler und Transaktion zusammenfinden.
     *
     * @return array<string, mixed>|null
     */
    private function trace(mixed $trace, string $path): ?array
    {
        $trace = $this->sanitizer->map($trace, $path);

        if ($trace === null) {
            return null;
        }

        $normalized = ['type' => 'trace'];

        foreach (['trace_id', 'span_id', 'parent_span_id'] as $field) {
            $value = $this->sanitizer->text($trace[$field] ?? null, $path.'.'.$field, 64);

            if ($value !== null) {
                // Kennungen sind Hex-Zeichen; die Schreibweise unterscheidet
                // sich je SDK. Bliebe sie stehen, fänden Fehler und
                // Transaktion desselben Vorgangs nicht zueinander.
                $normalized[$field] = strtolower($value);
            }
        }

        foreach (['op', 'status', 'origin', 'description'] as $field) {
            $value = $this->sanitizer->text($trace[$field] ?? null, $path.'.'.$field, 400);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $data = $this->sanitizer->map($trace['data'] ?? null, $path.'.data');

        if ($data !== null) {
            $normalized['data'] = $this->sanitizer->freeform($data, $path.'.data');
        }

        return count($normalized) === 1 ? null : $normalized;
    }
}
