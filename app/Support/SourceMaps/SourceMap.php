<?php

namespace App\Support\SourceMaps;

use App\Enums\SymbolicationDiagnosis;

/**
 * Eine Quellkarte (Source Map, Fassung 3) — und die Suche darin.
 *
 * Die Karte ist eine Tabelle: „Zeile 1, Spalte 4711 des ausgelieferten Bundles
 * entspricht Zeile 42, Spalte 8 von `src/warenkorb.ts`, und die Funktion hieß
 * dort `berechneSumme`". Gespeichert ist diese Tabelle nicht als Tabelle, sondern
 * als Zeichenkette in einer eigenen Kodierung, und die ist der Grund, dass diese
 * Klasse existiert.
 *
 * **Die Kodierung in einem Absatz.** Die Einträge einer erzeugten Zeile sind
 * durch Komma getrennt, die Zeilen durch Semikolon — eine leere Zeile ist damit
 * ein Semikolon ohne Inhalt und nicht etwa ein fehlender Eintrag. Jeder Eintrag
 * hat ein, vier oder fünf Zahlen: erzeugte Spalte, Quelldatei, Quellzeile,
 * Quellspalte, Name. Die Zahlen stehen als „variable length quantity" in
 * Base64 — fünf Nutzbits je Zeichen, das sechste sagt „es geht weiter", das
 * niederwertigste Bit der ersten Gruppe trägt das Vorzeichen. Und sie sind
 * **relativ** zum jeweils vorigen Eintrag: alle Felder außer der erzeugten
 * Spalte laufen über die ganze Karte weiter, die erzeugte Spalte beginnt in jeder
 * Zeile neu bei null. Wer die Fortschreibung falsch aufsetzt, bekommt eine Karte,
 * die am Anfang stimmt und ab der zweiten Zeile plausibel daneben liegt — der
 * Fehler, den man in Zeile 4000 nicht mehr findet.
 *
 * **Zerlegt wird erst, wenn gesucht wird.** Eine Karte eines echten Bundles hat
 * Hunderttausende Einträge; sie beim Einlesen alle zu zerlegen wäre auch dann
 * getan, wenn der Stacktrace drei Rahmen hat. Die Zerlegung passiert deshalb
 * einmal beim ersten Nachschlagen und wird behalten ({@see segments()}).
 */
final class SourceMap
{
    /**
     * Die Zeichen der Base64-Kodierung, umgekehrt nachschlagbar.
     *
     * @var array<string, int>|null
     */
    private static ?array $base64 = null;

    /**
     * Die zerlegten Einträge je erzeugter Zeile (0-basiert), aufsteigend nach
     * erzeugter Spalte.
     *
     * @var array<int, list<array{0: int, 1: int, 2: int, 3: int, 4: int|null}>>|null
     */
    private ?array $segments = null;

    /**
     * @param  list<string>  $sources  Die Quelldateien, wie sie in der Karte stehen.
     * @param  list<string|null>  $sourcesContent  Ihr Inhalt, sofern eingebettet.
     * @param  list<string>  $names  Die Bezeichner (Funktionsnamen).
     */
    private function __construct(
        private readonly string $mappings,
        private readonly array $sources,
        private readonly array $sourcesContent,
        private readonly array $names,
        private readonly string $sourceRoot,
        private readonly ?string $debugId,
    ) {}

    /**
     * Liest eine Quellkarte aus ihrem JSON.
     *
     * `null` heißt „das ist keine brauchbare Karte" und ist ein erwarteter
     * Ausgang, kein Ausnahmefall: hochgeladen wird, was ein Bauvorgang
     * abliefert, und das ist gelegentlich eine HTML-Fehlerseite mit der Endung
     * `.map`.
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_string($data['mappings'] ?? null)) {
            return null;
        }

        // Fassung 3 ist die einzige, die es in der Praxis gibt; ältere Fassungen
        // haben eine andere Kodierung. Fehlt die Angabe, wird 3 angenommen —
        // manche Werkzeuge lassen sie weg, und die Karte ist trotzdem eine.
        $version = $data['version'] ?? 3;

        if (! in_array($version, [3, '3'], true)) {
            return null;
        }

        $sources = [];

        foreach (is_array($data['sources'] ?? null) ? $data['sources'] : [] as $source) {
            // Auch der Nullwert bleibt ein Platz in der Liste: die Einträge der
            // Karte zeigen über den **Index** auf die Quelldatei, und wer eine
            // namenlose Quelle wegwirft, verschiebt alle folgenden.
            $sources[] = is_string($source) ? $source : '';
        }

        $contents = [];

        foreach (is_array($data['sourcesContent'] ?? null) ? $data['sourcesContent'] : [] as $content) {
            $contents[] = is_string($content) ? $content : null;
        }

        $names = [];

        foreach (is_array($data['names'] ?? null) ? $data['names'] : [] as $name) {
            $names[] = is_string($name) ? $name : '';
        }

        return new self(
            mappings: $data['mappings'],
            sources: $sources,
            sourcesContent: $contents,
            names: $names,
            sourceRoot: is_string($data['sourceRoot'] ?? null) ? $data['sourceRoot'] : '',
            // Die Debug-Kennung steht in der Karte selbst, sobald der Bauvorgang
            // eine erzeugt hat. Beide Schreibweisen kommen vor.
            debugId: self::text($data['debug_id'] ?? null) ?? self::text($data['debugId'] ?? null),
        );
    }

    /**
     * Sieht dieser Inhalt nach einer Quellkarte aus?
     *
     * Die Frage beim Hochladen — und sie wird am Inhalt entschieden und nicht an
     * der Endung: `app.js.map` ist eine Gewohnheit, keine Zusage. Geprüft wird
     * nur so viel, wie nötig ist, um Bundle und Karte zu unterscheiden; ob die
     * Karte brauchbar ist, entscheidet {@see fromJson()} beim Übersetzen.
     */
    public static function looksLikeSourceMap(string $content): bool
    {
        $head = ltrim(substr($content, 0, 512));

        if (! str_starts_with($head, '{')) {
            return false;
        }

        return str_contains($content, '"mappings"');
    }

    /**
     * Der Verweis auf die Quellkarte, der in einem Bundle steht.
     *
     * Gesucht wird von hinten: `//# sourceMappingURL=` steht am Ende der Datei,
     * und bei einem Bundle mit zwei Megabyte Inhalt ist das der Unterschied
     * zwischen einem Suchlauf und einem Blick. Der ältere Schreibweise mit `@`
     * statt `#` ist mitgeprüft — sie steht in älteren Bundles und ist nicht
     * falsch.
     *
     * Eine eingebettete Karte (`data:`) gibt `null`: sie ist kein Verweis auf
     * eine hochzuladende Datei, und ihre Auswertung ist eine andere Aufgabe als
     * die Zuordnung eines Pfades.
     */
    public static function referenceFrom(string $content): ?string
    {
        $tail = substr($content, -2048);

        $found = preg_match_all('~[/*][/*][@#]\s*sourceMappingURL\s*=\s*([^\s*]+)~', $tail, $matches);

        if ($found === false || $found === 0) {
            return null;
        }

        // Der **letzte** Verweis gilt. Mehrere kommen bei einem Bundle vor, das
        // aus mehreren Dateien zusammengesetzt ist und deren Kommentare
        // mitgenommen hat; gemeint ist dann der des Ganzen.
        $reference = trim((string) end($matches[1]));

        if ($reference === '' || str_starts_with($reference, 'data:')) {
            return null;
        }

        return $reference;
    }

    /**
     * Die Debug-Kennung, die ein Bauvorgang in ein Bundle geschrieben hat.
     *
     * Sie steht dort als `//# debugId=…` — dieselbe Kennung, die in der
     * Quellkarte im Feld `debug_id` liegt, und derselbe Zweck: die Zuordnung
     * ohne Adressen.
     */
    public static function debugIdFrom(string $content): ?string
    {
        $tail = substr($content, -2048);

        if (preg_match('~[/*][/*][@#]\s*debugId\s*=\s*([0-9a-fA-F-]{36})~', $tail, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    public function debugId(): ?string
    {
        return $this->debugId;
    }

    /**
     * Schlägt eine Stelle des erzeugten Codes in der Karte nach.
     *
     * Zeile und Spalte kommen 1-basiert herein, wie sie ein Stacktrace meldet,
     * und gehen 1-basiert heraus — die Karte selbst rechnet 0-basiert, und diese
     * Umrechnung ist genau einmal richtig zu machen, nämlich hier.
     *
     * **Gesucht wird der letzte Eintrag vor der Spalte, nicht der genaue.** Eine
     * Karte verzeichnet nicht jedes Zeichen, sondern die Stellen, an denen etwas
     * beginnt; die Spalte 4711 liegt in dem Bereich, der beim letzten Eintrag mit
     * kleinerer oder gleicher Spalte anfängt. Binär gesucht, weil eine erzeugte
     * Zeile eines minimierten Bundles zehntausende Einträge hat.
     *
     * Ohne Spaltenangabe wird der **erste** Eintrag der Zeile genommen. Das ist
     * eine Vermutung und wird auch so behandelt: bei einer minimierten Datei, die
     * ganz in einer Zeile steht, ist sie fast immer falsch — aber „irgendwo in
     * dieser Datei" ist mehr als nichts, und Rahmen ohne Spalte kommen von
     * älteren Browsern.
     */
    public function lookup(int $line, ?int $column = null): ?SourceLocation
    {
        $segments = $this->segments();
        $row = $segments[$line - 1] ?? null;

        if ($row === null || $row === []) {
            return null;
        }

        $segment = $column === null
            ? $row[0]
            : self::findSegment($row, $column - 1);

        if ($segment === null) {
            return null;
        }

        [, $sourceIndex, $sourceLine, $sourceColumn, $nameIndex] = $segment;

        $source = $this->sources[$sourceIndex] ?? null;

        if ($source === null) {
            return null;
        }

        return new SourceLocation(
            file: $this->resolveSource($source),
            line: $sourceLine + 1,
            column: $sourceColumn + 1,
            function: $nameIndex === null ? null : ($this->names[$nameIndex] ?? null),
            sourceIndex: $sourceIndex,
        );
    }

    /**
     * Der Quelltext um eine Stelle, Zeile für Zeile — sofern die Karte ihn
     * mitbringt.
     *
     * `sourcesContent` ist optional, und eine Karte ohne ihn ist nicht kaputt:
     * sie sagt, wo der Fehler steckt, nur nicht, was dort steht. Der Unterschied
     * gehört in die Diagnose ({@see SymbolicationDiagnosis::NoSourceContent}) und
     * nicht in einen leeren Ausschnitt, der wie ein Fehler aussieht.
     *
     * @return array{pre: list<string>, current: string, post: list<string>}|null
     */
    public function context(int $sourceIndex, int $line, int $lines): ?array
    {
        $content = $this->sourcesContent[$sourceIndex] ?? null;

        if (! is_string($content) || $content === '') {
            return null;
        }

        // Alle drei Zeilenenden, weil der Quelltext aus dem Bauvorgang eines
        // beliebigen Rechners kommt. Ohne `\r\n` zuerst bliebe je Zeile ein
        // Wagenrücklauf stehen — sichtbar als ein Zeichen zu viel am Ende.
        $all = preg_split("/\r\n|\n|\r/", $content);

        if ($all === false) {
            return null;
        }

        $index = $line - 1;

        if (! array_key_exists($index, $all)) {
            return null;
        }

        $from = max(0, $index - $lines);

        return [
            'pre' => array_slice($all, $from, $index - $from),
            'current' => $all[$index],
            'post' => array_slice($all, $index + 1, $lines),
        ];
    }

    /**
     * Der letzte Eintrag, dessen erzeugte Spalte nicht hinter der gesuchten
     * liegt.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int|null}>  $row
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int|null}|null
     */
    private static function findSegment(array $row, int $column): ?array
    {
        if ($row[0][0] > $column) {
            // Die gesuchte Spalte liegt vor dem ersten Eintrag der Zeile. Den
            // ersten trotzdem zu nehmen wäre eine Angabe über Code, den die
            // Karte nicht kennt.
            return null;
        }

        $low = 0;
        $high = count($row) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($row[$middle][0] <= $column) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $row[$low];
    }

    /**
     * Zerlegt die Zeichenkette der Karte — einmal.
     *
     * @return array<int, list<array{0: int, 1: int, 2: int, 3: int, 4: int|null}>>
     */
    private function segments(): array
    {
        if ($this->segments !== null) {
            return $this->segments;
        }

        $segments = [];

        // Die vier fortlaufenden Zähler. Sie laufen über die **ganze** Karte
        // weiter; nur die erzeugte Spalte beginnt in jeder Zeile neu.
        $sourceIndex = 0;
        $sourceLine = 0;
        $sourceColumn = 0;
        $nameIndex = 0;

        foreach (explode(';', $this->mappings) as $lineNumber => $line) {
            $generatedColumn = 0;

            if ($line === '') {
                continue;
            }

            $row = [];

            foreach (explode(',', $line) as $segment) {
                if ($segment === '') {
                    continue;
                }

                $fields = self::decode($segment);

                if ($fields === []) {
                    continue;
                }

                $generatedColumn += $fields[0];

                // Ein Eintrag mit nur einer Zahl sagt „hier beginnt etwas, zu dem
                // ich keine Quelle habe" — der Rest des Bundles, den kein
                // geschriebener Code erzeugt hat. Er wird übersprungen und nicht
                // mit der vorherigen Quelle gefüllt: das wäre eine erfundene
                // Zuordnung.
                if (count($fields) < 4) {
                    continue;
                }

                $sourceIndex += $fields[1];
                $sourceLine += $fields[2];
                $sourceColumn += $fields[3];

                if (count($fields) >= 5) {
                    $nameIndex += $fields[4];
                    $name = $nameIndex;
                } else {
                    $name = null;
                }

                $row[] = [$generatedColumn, $sourceIndex, $sourceLine, $sourceColumn, $name];
            }

            if ($row !== []) {
                // Die Einträge stehen in der Karte aufsteigend nach erzeugter
                // Spalte — die binäre Suche verlässt sich darauf, und ein
                // Werkzeug, das sich daran nicht hält, würde sie stillschweigend
                // falsch beantworten lassen.
                usort($row, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

                $segments[$lineNumber] = $row;
            }
        }

        return $this->segments = $segments;
    }

    /**
     * Die Zahlen eines Eintrags.
     *
     * @return list<int>
     */
    private static function decode(string $segment): array
    {
        self::$base64 ??= array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'));

        $values = [];
        $value = 0;
        $shift = 0;

        foreach (str_split($segment) as $character) {
            $digit = self::$base64[$character] ?? null;

            if ($digit === null) {
                // Ein Zeichen, das nicht zur Kodierung gehört. Die Karte ist ab
                // hier nicht mehr zu deuten; der Eintrag wird verworfen, statt
                // mit einer halben Zahl weiterzurechnen.
                return [];
            }

            $continues = ($digit & 32) !== 0;
            $value += ($digit & 31) << $shift;

            if ($continues) {
                $shift += 5;

                continue;
            }

            // Das niederwertigste Bit ist das Vorzeichen — und `-0` gibt es in
            // dieser Kodierung: es ist die kleinste darstellbare Zahl und käme
            // bei einer Karte vor, die aus einem Zähler-Überlauf entstanden ist.
            $negative = ($value & 1) === 1;
            $value >>= 1;

            $values[] = $negative ? -$value : $value;

            $value = 0;
            $shift = 0;
        }

        return $values;
    }

    /**
     * Die Quelldatei, wie sie angezeigt wird.
     *
     * Zwei Dinge werden dabei geradegezogen: der `sourceRoot` wird
     * vorangestellt (er steht einmal für die ganze Karte und fehlt in den
     * einzelnen Angaben), und das Präfix der Bauwerkzeuge fällt weg —
     * `webpack:///./src/warenkorb.ts` wird `src/warenkorb.ts`. Das ist keine
     * Kosmetik: das Präfix ist eine Angabe über das Bauwerkzeug, und wer den
     * Dateinamen in seinem Editor sucht, sucht ohne es.
     */
    private function resolveSource(string $source): string
    {
        if ($this->sourceRoot !== '' && ! str_contains($source, '://') && ! str_starts_with($source, '/')) {
            $source = rtrim($this->sourceRoot, '/').'/'.ltrim($source, '/');
        }

        $source = (string) preg_replace('#^[a-z0-9.+-]+://+#i', '', $source);

        return (string) preg_replace('#^\./#', '', $source);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
