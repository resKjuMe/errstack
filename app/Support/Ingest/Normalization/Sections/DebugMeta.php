<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Die geladenen Dateien einer Meldung samt ihrer Debug-Kennungen
 * (`debug_meta.images`).
 *
 * Der Abschnitt ist die Voraussetzung dafür, einen minimierten Stacktrace ohne
 * Adressen zurückübersetzen zu können (R5). Ein Bauvorgang schreibt je Bundle
 * eine Nummer in die Datei **und** in die Quellkarte; das SDK meldet, welche
 * Nummer zu welcher geladenen Adresse gehört. Damit ist die Zuordnung eindeutig —
 * unabhängig davon, unter welcher Adresse ein Auslieferungsnetz die Datei
 * ausgeliefert hat und ob die Meldung überhaupt eine Version trägt.
 *
 * **Nur die Felder, die dafür gebraucht werden.** Sentry führt in diesem
 * Abschnitt auch die Angaben für die Symbolisierung nativer Abstürze
 * (`image_addr`, `debug_file`, Architektur) — die gehören zu einer anderen
 * Aufgabe, und ein Fach, das alles mitnimmt, was vorbeikommt, wäre keine
 * Normalisierung. Was hier nicht steht, ist damit nicht verloren: die Rohdaten
 * der Meldung bleiben in der Eingangsablage.
 */
final class DebugMeta
{
    /**
     * Wie viele Bilder eine Meldung mitbringen darf.
     *
     * Eine Anwendung lädt Dutzende Bundles, keine Tausende. Die Grenze ist
     * dieselbe Überlegung wie bei jeder anderen Liste hier: was darüber liegt,
     * ist keine Anwendung, sondern ein Fehler in der Bauumgebung.
     */
    private const MAX_IMAGES = 200;

    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $debugMeta, string $path): ?array
    {
        $debugMeta = $this->sanitizer->map($debugMeta, $path);

        if ($debugMeta === null) {
            return null;
        }

        $images = [];

        foreach ($this->sanitizer->items($debugMeta['images'] ?? null, $path.'.images', self::MAX_IMAGES) as $index => $image) {
            $normalized = $this->image($image, $path.'.images.'.$index);

            if ($normalized !== null) {
                $images[] = $normalized;
            }
        }

        return $images === [] ? null : ['images' => $images];
    }

    /**
     * Ein Bild — eine geladene Datei mit ihrer Kennung.
     *
     * Ohne Kennung wird der Eintrag verworfen: er ist genau dafür da. Ein Bild
     * ohne `debug_id` sagt nur, dass eine Datei geladen wurde, und das steht am
     * Stacktrace besser.
     *
     * @return array<string, string>|null
     */
    private function image(mixed $image, string $path): ?array
    {
        $image = $this->sanitizer->map($image, $path);

        if ($image === null) {
            return null;
        }

        $debugId = $this->sanitizer->text($image['debug_id'] ?? null, $path.'.debug_id', 40);

        if ($debugId === null) {
            return null;
        }

        $normalized = ['debug_id' => strtolower($debugId)];

        // Die Adresse, unter der die Datei geladen wurde. Sie darf fehlen: ein
        // SDK, das die Kennung aus dem Bundle selbst liest, kennt sie
        // ({@see \App\Support\SourceMaps\Symbolicator::debugIds()} ordnet dann
        // nichts zu, aber die Kennung steht wenigstens da).
        $codeFile = $this->sanitizer->text($image['code_file'] ?? null, $path.'.code_file', 500);

        if ($codeFile !== null) {
            $normalized['code_file'] = $codeFile;
        }

        // `sourcemap` bei JavaScript, `macho`/`elf` bei nativen Abstürzen. Die
        // Angabe wird nicht ausgewertet, aber mitgeführt: sie ist die Antwort auf
        // „warum wurde hier nichts übersetzt?", wenn eine Meldung Bilder trägt,
        // die keine Bundles sind.
        $type = $this->sanitizer->text($image['type'] ?? null, $path.'.type', 40);

        if ($type !== null) {
            $normalized['type'] = strtolower($type);
        }

        return $normalized;
    }
}
