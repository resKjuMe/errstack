<?php

namespace App\Support\Releases;

/**
 * Welche Zeilen einer Datei ein Commit angefasst hat.
 *
 * Die Angabe kommt auf zwei Wegen herein, und das ist keine Bequemlichkeit,
 * sondern folgt dem, was die Absender haben:
 *
 *  - **`patch`** — der Unterschied im üblichen Format, so wie ihn GitHub und
 *    GitLab je Datei zurückgeben. Wer eine Anbindung baut (X1/X2), reicht ihn
 *    unverändert durch.
 *  - **`lines`** — fertige Bereiche, für alles andere: ein Auslieferungs-Skript,
 *    das `git diff` selbst auswertet, soll dafür keinen Unterschied bauen
 *    müssen, den wir gleich wieder zerlegen.
 *
 * Herausgegeben wird beides Mal dieselbe Form: eine aufsteigend sortierte Liste
 * von Paaren `[von, bis]` mit **Zeilennummern der neuen Fassung**. Die neue und
 * nicht die alte, weil der Stacktrace aus dem ausgelieferten Stand stammt — er
 * nennt Zeile 42 der Datei, wie sie nach dem Commit aussieht.
 *
 * Gelöschte Zeilen fallen dabei nicht unter den Tisch: sie bekommen die Stelle,
 * an der sie standen. Eine gelöschte Zeile ist die häufigste Ursache eines
 * Fehlers direkt daneben, und sie zu übergehen hieße, genau den Commit
 * auszublenden, der die Prüfung entfernt hat.
 */
final class PatchLines
{
    /**
     * Mehr Bereiche je Datei behalten wir nicht.
     *
     * Ein Commit, der eine Datei an vierzig verstreuten Stellen anfasst, ist
     * eine Umformatierung — und für die Frage „welche Änderung war das?" sagt
     * eine Liste, die halb so lang ist wie die Datei, nichts mehr aus. Die
     * Grenze ist zugleich der Schutz davor, dass ein einziger erzeugter
     * Unterschied eine Zeile aufbläht, die zu einer Datei gehört.
     */
    private const MAX_RANGES = 50;

    /**
     * Die Bereiche aus dem Eintrag einer Dateiliste — oder `null`, wenn er
     * keine nennt.
     *
     * `null` und nicht `[]`: „nicht bekannt" (sentry-cli schickt nur Pfad und
     * Art) ist etwas anderes als „keine Zeile geändert" (eine Umbenennung), und
     * der Abgleich behandelt beides verschieden.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{int, int}>|null
     */
    public static function fromEntry(array $entry): ?array
    {
        // `is_array` und nicht `array_key_exists`: ein `lines: null` ist keine
        // Aussage über die Zeilen, sondern das Fehlen einer — und dürfte
        // deshalb nicht als „keine Zeile geändert" abgelegt werden.
        if (is_array($entry['lines'] ?? null)) {
            return self::normalize(self::fromLines($entry['lines']));
        }

        if (is_string($entry['patch'] ?? null)) {
            return self::normalize(self::fromPatch($entry['patch']));
        }

        return null;
    }

    /**
     * Fertige Bereiche, nachsichtig gelesen.
     *
     * Erlaubt sind die drei Schreibweisen, in denen so etwas ankommt: eine
     * einzelne Zeilennummer, ein Paar `[von, bis]` und ein Objekt
     * `{"start": …, "end": …}`. Alles andere wird übergangen und nicht
     * abgewiesen — eine unbrauchbare Angabe kostet die Genauigkeit dieser einen
     * Datei, aber nicht den Baulauf.
     *
     * @param  array<mixed>  $lines
     * @return list<array{int, int}>
     */
    private static function fromLines(array $lines): array
    {
        $ranges = [];

        foreach ($lines as $line) {
            if (is_int($line) || (is_string($line) && ctype_digit($line))) {
                $number = (int) $line;

                if ($number > 0) {
                    $ranges[] = [$number, $number];
                }

                continue;
            }

            if (! is_array($line)) {
                continue;
            }

            $start = $line['start'] ?? $line[0] ?? null;
            $end = $line['end'] ?? $line[1] ?? $start;

            if (! is_numeric($start) || ! is_numeric($end)) {
                continue;
            }

            $start = (int) $start;
            $end = (int) $end;

            if ($start <= 0 || $end < $start) {
                continue;
            }

            $ranges[] = [$start, $end];
        }

        return $ranges;
    }

    /**
     * Die geänderten Stellen aus einem Unterschied im üblichen Format.
     *
     * Gezählt wird an der **neuen** Fassung mit: der Kopf eines Blocks
     * (`@@ -12,7 +12,9 @@`) sagt, wo sie beginnt, danach schiebt jede
     * behaltene und jede hinzugefügte Zeile den Zähler weiter, eine entfernte
     * nicht. Aufgenommen wird, was hinzugefügt wurde — und die Stelle, an der
     * etwas entfernt wurde.
     *
     * **Nicht der ganze Block.** Ein Block bringt drei Zeilen Umgebung mit, und
     * die als „geändert" zu führen hieße, jeden Fehler im Umkreis von drei
     * Zeilen demselben Commit anzuhängen — bei mehreren Commits an derselben
     * Datei wäre die Reihenfolge der Verdächtigen dann Zufall.
     *
     * @return list<array{int, int}>
     */
    private static function fromPatch(string $patch): array
    {
        $ranges = [];
        $line = 0;
        $inHunk = false;

        foreach (preg_split('/\R/', $patch) ?: [] as $row) {
            if (str_starts_with($row, '@@')) {
                // `@@ -alt,anzahl +neu,anzahl @@ überschrift`
                if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $row, $match) !== 1) {
                    $inHunk = false;

                    continue;
                }

                $line = (int) $match[1];
                $inHunk = true;

                continue;
            }

            if (! $inHunk) {
                continue;
            }

            $marker = $row === '' ? ' ' : $row[0];

            if ($marker === '+') {
                $ranges[] = [$line, $line];
                $line++;

                continue;
            }

            if ($marker === '-') {
                // Die Stelle, an der die Zeile stand — sie hat in der neuen
                // Fassung keine eigene Nummer mehr, und die des Nachfolgers ist
                // die nächstbeste Auskunft. Der Zähler bleibt stehen.
                $ranges[] = [$line, $line];

                continue;
            }

            if ($marker === '\\') {
                // „\ No newline at end of file" — eine Bemerkung über die Datei
                // und keine Zeile darin.
                continue;
            }

            $line++;
        }

        return $ranges;
    }

    /**
     * Sortieren, Angrenzendes zusammenziehen, kürzen.
     *
     * Das Zusammenziehen ist nicht nur Kosmetik: ein Block aus vierzig
     * hinzugefügten Zeilen kommt oben als vierzig Paare an, und ohne diesen
     * Schritt wäre die Grenze von {@see MAX_RANGES} nach der ersten Hälfte
     * erreicht.
     *
     * @param  list<array{int, int}>  $ranges
     * @return list<array{int, int}>
     */
    private static function normalize(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($ranges as $range) {
            $last = array_key_last($merged);

            // `+ 1`: zwei Bereiche, die sich berühren (12–14 und 15–18), sind
            // eine Änderung mit einer behaltenen Zeile dazwischen und keine
            // zwei.
            if ($last !== null && $range[0] <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);

                continue;
            }

            $merged[] = $range;
        }

        return array_slice($merged, 0, self::MAX_RANGES);
    }
}
