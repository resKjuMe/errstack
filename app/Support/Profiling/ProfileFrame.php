<?php

namespace App\Support\Profiling;

use App\Support\Performance\PayloadReader;

/**
 * Ein Rahmen des Aufrufstapels: eine Funktion an einer Stelle im Quelltext.
 *
 * Die Rahmen kommen einmal je Profil als Tabelle, und der Aufrufbaum verweist
 * über den Platz in dieser Tabelle darauf. Das ist die Form, die Sentry
 * schickt, und es ist zugleich die Form, in der wir ablegen — ausgeschrieben
 * stünde derselbe Rahmen in einem Baum hundertfach.
 */
final class ProfileFrame
{
    /**
     * Der Name, unter dem eine Funktion ohne eigenen geführt wird.
     *
     * Nicht übersetzt: der Wert steht in der Ablage und ist der Schlüssel, unter
     * dem beim Zusammenfassen mehrerer Profile verglichen wird. Übersetzt hätte
     * dieselbe Funktion je Sprache des Betrachters einen anderen Namen — und der
     * Vergleich zwischen zwei Versionen fiele auseinander, sobald jemand die
     * Sprache umstellt.
     */
    public const UNKNOWN = '<unknown>';

    public const FUNCTION_LIMIT = 255;

    public const FILE_LIMIT = 255;

    public const MODULE_LIMIT = 255;

    public function __construct(
        public readonly string $function,
        public readonly ?string $module,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly bool $inApp,
    ) {}

    /**
     * Liest einen Rahmen aus dem gemeldeten Feld-Baum.
     *
     * Gibt **immer** einen Rahmen zurück, nie `null`. Ein Rahmen ohne
     * erkennbaren Namen ist nicht wertlos: er steht im Aufrufstapel zwischen
     * zweien, die einen haben, und ihn wegzulassen hieße, die Verschachtelung zu
     * verfälschen — der Aufrufer einer Funktion wäre plötzlich deren Großvater.
     *
     * @param  array<mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            function: PayloadReader::text($raw['function'] ?? null, self::FUNCTION_LIMIT) ?? self::UNKNOWN,
            module: PayloadReader::text($raw['module'] ?? $raw['package'] ?? null, self::MODULE_LIMIT),
            // `filename` ist der Weg innerhalb des Projekts, `abs_path` der
            // vollständige des Rechners, auf dem gemessen wurde. Der kürzere
            // zuerst: er ist der, der zwischen zwei Servern derselbe bleibt, und
            // damit der, über den sich zwei Profile vergleichen lassen.
            file: PayloadReader::text($raw['filename'] ?? $raw['abs_path'] ?? null, self::FILE_LIMIT),
            line: self::line($raw['lineno'] ?? null),
            // Ohne Angabe gilt der Rahmen als fremd. Die Zusage der Anzeige ist
            // „das ist euer Code" — sie muss falsch-negativ ausfallen, nicht
            // falsch-positiv: eine Bibliotheksfunktion, die als eigener Code
            // hervorgehoben wird, schickt die Suche in die falsche Richtung.
            inApp: ($raw['in_app'] ?? null) === true,
        );
    }

    /**
     * Die Kennung, unter der zwei Rahmen als **dieselbe Funktion** gelten.
     *
     * Ohne Zeilennummer, und das ist die eigentliche Entscheidung hier: sie
     * ändert sich bei jeder Bearbeitung der Datei. Ginge sie ein, wären
     * dieselbe Funktion vor und nach einem eingefügten Kommentar zwei
     * verschiedene — und genau der Vergleich zweier Versionen, für den das
     * Zusammenfassen da ist, fiele in zwei Hälften auseinander, ohne dass sich
     * an der Laufzeit etwas geändert hätte.
     *
     * Die Zeilennummer bleibt trotzdem am Rahmen: sie führt zur Fundstelle. Sie
     * taugt nur nicht als Teil der Kennung.
     */
    public function key(): string
    {
        return implode("\0", [$this->module ?? '', $this->function, $this->file ?? '']);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromStorage(array $raw): self
    {
        return new self(
            function: is_string($raw['function'] ?? null) ? $raw['function'] : self::UNKNOWN,
            module: is_string($raw['module'] ?? null) ? $raw['module'] : null,
            file: is_string($raw['file'] ?? null) ? $raw['file'] : null,
            line: is_int($raw['line'] ?? null) ? $raw['line'] : null,
            inApp: ($raw['in_app'] ?? null) === true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'function' => $this->function,
            'module' => $this->module,
            'file' => $this->file,
            'line' => $this->line,
            'in_app' => $this->inApp,
        ];
    }

    /**
     * Die Zeilennummer, sofern sie eine sein kann.
     *
     * Null und negative Werte kommen aus SDKs, die „unbekannt" so ausdrücken;
     * als Zeilennummer angezeigt wären sie eine Falschauskunft.
     */
    private static function line(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
