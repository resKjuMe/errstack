<?php

namespace App\Support\Ingest\Normalization;

/**
 * Die Obergrenzen der Normalisierung.
 *
 * Die Aufnahme kennt nur eine Grenze: wie groß eine Meldung im Ganzen sein
 * darf. Das genügt hier nicht. Eine Meldung von knapp einem Megabyte ist
 * erlaubt — sie darf aber nicht aus einem einzigen Fehlertext von einem
 * Megabyte bestehen, denn der wird in Listen, Suchergebnissen und
 * Benachrichtigungen wieder ausgepackt. Die Grenzen hier verteilen das erlaubte
 * Gesamtgewicht auf die Abschnitte.
 *
 * Eigene Klasse und nicht `config()` an der Verwendungsstelle: die Werte werden
 * je Meldung dutzendfach gelesen, und ein Abschnitts-Normalisierer soll sie
 * verlangen können, statt sie sich zu holen — dann ist im Test eine andere
 * Grenze ein Konstruktor-Wert und keine globale Umstellung.
 */
final class Limits
{
    public function __construct(
        /** Zeichen je Textfeld (Fehlertext, Dateiname, Kennung …). */
        public readonly int $stringChars = 8_192,

        /** Zeichen je Zeile Quelltext im Stacktrace-Umfeld. */
        public readonly int $sourceLineChars = 512,

        /** Verschachtelte Ausnahmen („caused by") je Meldung. */
        public readonly int $exceptions = 25,

        /** Stapelrahmen je Stacktrace. */
        public readonly int $frames = 250,

        /** Zeilen Quelltext vor und nach der betroffenen Zeile. */
        public readonly int $contextLines = 10,

        /** Ausführungsstränge je Meldung. */
        public readonly int $threads = 25,

        /** Spuren je Meldung. */
        public readonly int $breadcrumbs = 100,

        /** Einträge je Schlüssel-Wert-Abschnitt (Marken, Beiwerk, Kopfzeilen …). */
        public readonly int $entries = 100,

        /** Verschachtelungstiefe in frei geformten Abschnitten (`extra`, Kontexte). */
        public readonly int $depth = 5,
    ) {}

    /**
     * Die Grenzen, wie sie in `config/ingest.php` stehen.
     *
     * Fehlt ein Wert oder ist er unbrauchbar, gilt der Vorgabewert des
     * Konstruktors. Eine kaputte Zeile in der Konfiguration darf die
     * Verarbeitung nicht anhalten — sie würde sonst jede Meldung des Systems
     * zum Scheitern bringen, und zwar erst im Hintergrund, lange nach dem
     * Neustart, der sie eingeführt hat.
     */
    public static function fromConfig(): self
    {
        $configured = config('ingest.normalization.limits');
        $configured = is_array($configured) ? $configured : [];

        $defaults = new self;

        $read = static function (string $key, int $default) use ($configured): int {
            $value = $configured[$key] ?? null;

            return is_int($value) && $value > 0 ? $value : $default;
        };

        return new self(
            stringChars: $read('string_chars', $defaults->stringChars),
            sourceLineChars: $read('source_line_chars', $defaults->sourceLineChars),
            exceptions: $read('exceptions', $defaults->exceptions),
            frames: $read('frames', $defaults->frames),
            contextLines: $read('context_lines', $defaults->contextLines),
            threads: $read('threads', $defaults->threads),
            breadcrumbs: $read('breadcrumbs', $defaults->breadcrumbs),
            entries: $read('entries', $defaults->entries),
            depth: $read('depth', $defaults->depth),
        );
    }
}
