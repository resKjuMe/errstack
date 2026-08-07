<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Der Meldungstext — bei einer Nachricht ohne Ausnahme der ganze Inhalt.
 *
 * Sentry führt dafür zwei Felder mit derselben Bedeutung: `message` und
 * `logentry`. Historisch war `message` eine bloße Zeichenkette, heute ist es
 * ein Objekt mit Vorlage und Werten, und `logentry` ist der neuere Name dafür.
 * Die SDKs schicken alle drei Formen, gelegentlich zwei davon gleichzeitig.
 *
 * Der Grund für die Vorlage ist wichtig genug, sie zu bewahren: „Nutzer 4711
 * nicht gefunden" und „Nutzer 4712 nicht gefunden" sind derselbe Fehler. Ab I5
 * wird nach der **Vorlage** gruppiert, nicht nach dem eingesetzten Text —
 * sonst entstünde je Kennung eine eigene Fehlergruppe. Deshalb werden hier
 * beide gehalten: `template` für die Zuordnung, `formatted` für die Anzeige.
 */
final class Message
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * Führt `message` und `logentry` zu einem Abschnitt zusammen.
     *
     * `logentry` hat Vorrang, wenn beide da sind: das ist die Form, die ein
     * SDK ausdrücklich gefüllt hat, während `message` bei manchen SDKs
     * nebenher aus dem Ergebnis mitläuft.
     *
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $message, mixed $logentry, string $path): ?array
    {
        return $this->entry($logentry, $path.'.logentry')
            ?? $this->entry($message, $path.'.message');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(mixed $value, string $path): ?array
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $text = $this->sanitizer->text($value, $path);

            return $text === null ? null : ['formatted' => $text];
        }

        $entry = $this->sanitizer->map($value, $path);

        if ($entry === null) {
            return null;
        }

        $normalized = [];

        $formatted = $this->sanitizer->text($entry['formatted'] ?? null, $path.'.formatted');
        $template = $this->sanitizer->text($entry['message'] ?? null, $path.'.message');

        $params = $this->params($entry['params'] ?? null, $path.'.params');

        // Ohne eingesetzten Text ist die Vorlage die beste Anzeige, die es
        // gibt — besser als ein leeres Feld, wo etwas stehen sollte.
        if ($formatted === null && $template !== null && $params === []) {
            $formatted = $template;
            $template = null;
        }

        if ($formatted !== null) {
            $normalized['formatted'] = $formatted;
        }

        if ($template !== null) {
            $normalized['template'] = $template;
        }

        if ($params !== []) {
            $normalized['params'] = $params;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Die Werte, die in die Vorlage eingesetzt wurden.
     *
     * Sie kommen als Liste (`%s`-Stil) und als Objekt (benannte Platzhalter).
     * Beide Formen bleiben erhalten, weil die Vorlage sonst nicht mehr
     * auszufüllen wäre.
     *
     * @return array<array-key, mixed>
     */
    private function params(mixed $params, string $path): array
    {
        if ($params === null) {
            return [];
        }

        $normalized = $this->sanitizer->freeform($params, $path);

        return is_array($normalized) ? $normalized : [];
    }
}
