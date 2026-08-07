<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Der Stacktrace: die Kette der Stapelrahmen bis zur Fehlerstelle.
 *
 * Der wichtigste Abschnitt einer Fehlermeldung — an ihm hängt, ob jemand den
 * Fehler findet, und ab I5 auch, welche Meldungen als derselbe Fehler gelten.
 * Deshalb ist hier mehr Sorgfalt angebracht als anderswo.
 *
 * Zwei Eigenheiten sind zu beachten:
 *
 * **Die Reihenfolge.** Sentry zählt den ältesten Rahmen zuerst auf; der Rahmen,
 * in dem es knallte, steht am Ende. Diese Reihenfolge wird nicht angetastet —
 * weder hier noch beim Kappen zu langer Ketten, das deshalb hinten abschneidet
 * und nicht vorn.
 *
 * **`in_app`.** Der Unterschied zwischen eigenem Code und fremdem Rahmenwerk
 * ist bei einer Ausnahme aus zweihundert Rahmen die einzige brauchbare
 * Abkürzung. Fehlt die Angabe, wird sie **nicht** geraten: der Wert bleibt
 * `null` und heißt „unbekannt". Ein geratenes `false` würde den Rahmen
 * verstecken, in dem der Fehler tatsächlich sitzt.
 */
final class Frames
{
    /**
     * Die Felder eines Rahmens, die Text tragen.
     */
    private const TEXT_FIELDS = [
        'filename',
        'abs_path',
        'function',
        'raw_function',
        'module',
        'package',
        'platform',
        'instruction_addr',
        'symbol_addr',
        'image_addr',
        'addr_mode',
    ];

    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * Normalisiert einen Stacktrace-Abschnitt (`{"frames": [...]}` oder die
     * nackte Liste).
     *
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $stacktrace, string $path): array
    {
        if (is_array($stacktrace) && ! array_is_list($stacktrace) && array_key_exists('frames', $stacktrace)) {
            $stacktrace = $stacktrace['frames'];
        }

        $raw = $this->sanitizer->items($stacktrace, $path, $this->sanitizer->limits()->frames);

        $frames = [];

        foreach ($raw as $index => $frame) {
            $normalized = $this->frame($frame, $path.'.'.$index);

            if ($normalized !== null) {
                $frames[] = $normalized;
            }
        }

        return $frames;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function frame(mixed $frame, string $path): ?array
    {
        $frame = $this->sanitizer->map($frame, $path);

        if ($frame === null) {
            return null;
        }

        $normalized = [];

        foreach (self::TEXT_FIELDS as $field) {
            $value = $this->sanitizer->text($frame[$field] ?? null, $path.'.'.$field);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        foreach (['lineno' => 'lineno', 'colno' => 'colno'] as $field => $key) {
            $value = $this->sanitizer->integer($frame[$field] ?? null, $path.'.'.$field);

            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        $inApp = $this->sanitizer->boolean($frame['in_app'] ?? null, $path.'.in_app');

        if ($inApp !== null) {
            $normalized['in_app'] = $inApp;
        }

        $contextLine = $this->sanitizer->sourceLine($frame['context_line'] ?? null, $path.'.context_line');

        if ($contextLine !== null) {
            $normalized['context_line'] = $contextLine;
        }

        foreach (['pre_context', 'post_context'] as $field) {
            $lines = $this->sourceLines($frame[$field] ?? null, $path.'.'.$field);

            if ($lines !== []) {
                $normalized[$field] = $lines;
            }
        }

        // Die örtlichen Variablen eines Rahmens sind der Ort, an dem am
        // ehesten personenbezogene Daten stehen — das Scrubbing (I7) greift
        // hier an. Bis dahin werden sie wie jeder frei geformte Abschnitt
        // behandelt: übernommen, in Tiefe und Umfang begrenzt.
        $vars = $this->sanitizer->map($frame['vars'] ?? null, $path.'.vars');

        if ($vars !== null) {
            $normalized['vars'] = $this->sanitizer->freeform($vars, $path.'.vars');
        }

        // Ein Rahmen ohne jede Angabe ist keiner. Er entsteht, wenn ein SDK
        // eine Lücke im Stapel mit einem leeren Objekt füllt — sie mitzuführen
        // hieße, den Stacktrace mit Leerzeilen zu strecken.
        return $normalized === [] ? null : $normalized;
    }

    /**
     * Die Quelltextzeilen vor oder nach der Fehlerstelle.
     *
     * @return list<string>
     */
    private function sourceLines(mixed $value, string $path): array
    {
        $raw = $this->sanitizer->items($value, $path, $this->sanitizer->limits()->contextLines);

        $lines = [];

        foreach ($raw as $index => $line) {
            // Auch die leere Zeile bleibt: sie ist im Quelltext eine echte
            // Zeile, und wer sie wegwirft, verschiebt alle folgenden gegen die
            // Zeilennummer, an der sie hängen.
            $lines[] = $this->sanitizer->sourceLine($line, $path.'.'.$index) ?? '';
        }

        return $lines;
    }
}
