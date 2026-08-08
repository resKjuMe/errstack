<?php

namespace App\Support\Ownership;

use App\Enums\OwnershipMatcher;
use App\Models\Organization;
use App\Models\OwnershipRule;
use App\Support\Issues\IssueAssignee;

/**
 * Übernimmt eine CODEOWNERS-Datei als Zuständigkeits-Regeln.
 *
 * **Warum überhaupt:** wer eine CODEOWNERS-Datei hat, hat die Frage „wem gehört
 * dieser Ordner?" bereits beantwortet — einmal, gemeinsam und an der Stelle, an
 * der sie beim Ändern des Codes auffällt. Sie hier noch einmal von Hand
 * einzutippen hieße, dieselbe Antwort ein zweites Mal zu pflegen; die zweite
 * Fassung wäre nach einem halben Jahr die falsche.
 *
 * **Eingefügt und nicht angebunden.** Der Text wird übergeben, nicht aus dem
 * Repository geholt — dasselbe Verhältnis wie bei den Commits (R2): solange es
 * keine Anbindung gibt, ist „verbinden" ein Einfügen. Der Vorteil ist nicht nur
 * der geringere Aufwand: der Import ist damit ein Vorgang, den jemand auslöst
 * und dessen Ergebnis er sieht, statt eines stillen Abgleichs, der eines
 * Morgens die Zuständigkeiten umgeschrieben hat.
 *
 * **Die Reihenfolge der Datei bleibt die Reihenfolge der Liste.** Beide lösen
 * mehrere Treffer gleich auf — die zuletzt passende Zeile gewinnt
 * ({@see Ownership}) —, und nur deshalb bedeutet die importierte Liste dasselbe
 * wie die Datei.
 *
 * **Was nicht übernommen werden kann, wird gemeldet und nicht verschluckt.**
 * Eine Zeile, deren Zuständige es hier nicht gibt (`@ein-github-konto`, das
 * keinem Errstack-Konto entspricht), ergäbe eine Regel, die niemandem etwas
 * zuweist. Sie anzulegen wäre die bequeme und die unehrliche Wahl: die Liste
 * sähe vollständig aus und wäre es nicht.
 */
final class CodeownersImport
{
    /**
     * Wie viele Zeilen eine Datei haben darf, bevor abgeschnitten wird.
     *
     * Zweimal so viele wie ein Projekt Regeln haben darf: Kommentare und
     * Leerzeilen machen in einer gepflegten Datei leicht die Hälfte aus, und
     * die Grenze soll die Nutzzeilen treffen und nicht das Beiwerk.
     */
    public const MAX_LINES = OwnershipRule::MAX_PER_PROJECT * 2;

    /**
     * Liest den Text und sagt, was daraus würde.
     *
     * Getrennt vom Anlegen, weil die Oberfläche beides braucht: erst zeigen,
     * was ankommt, dann übernehmen. Zwei Fassungen derselben Auswertung wären
     * die sichere Art, eine Vorschau zu bauen, die etwas anderes zeigt als das
     * Ergebnis.
     *
     * @return array{rules: list<array{matcher: string, pattern: string, owners: list<string>, line: int}>, skipped: list<array{line: int, text: string, reason: string}>}
     */
    public function parse(string $contents, Organization $organization): array
    {
        $rules = [];
        $skipped = [];

        foreach (self::lines($contents) as $number => $text) {
            $trimmed = trim($text);

            // Leerzeilen und Kommentare sind kein Fehler, sondern der Rahmen,
            // in dem eine solche Datei lesbar bleibt. Sie werden übergangen und
            // ausdrücklich **nicht** als übersprungen gemeldet — eine Meldung
            // „Zeile 3 ist ein Kommentar" wäre Lärm, in dem die echten
            // Hinweise untergehen.
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $trimmed) ?: [];
            $pattern = array_shift($parts);

            if (! is_string($pattern) || $parts === []) {
                $skipped[] = self::skip($number, $trimmed, 'no_owners');

                continue;
            }

            $owners = [];

            foreach ($parts as $part) {
                $term = self::owner($part);

                if ($term !== null && IssueAssignee::resolve($term, $organization) !== null) {
                    $owners[] = $term;
                }
            }

            if ($owners === []) {
                $skipped[] = self::skip($number, $trimmed, 'unknown_owners');

                continue;
            }

            $translated = self::pattern($pattern);

            if ($translated === null) {
                $skipped[] = self::skip($number, $trimmed, 'bad_pattern');

                continue;
            }

            $rules[] = [
                'matcher' => OwnershipMatcher::Path->value,
                'pattern' => $translated,
                'owners' => array_values(array_unique($owners)),
                'line' => $number,
            ];
        }

        return ['rules' => $rules, 'skipped' => $skipped];
    }

    /**
     * Der Zuständige einer CODEOWNERS-Zeile in unserer Schreibweise.
     *
     * Drei Formen kommen vor, und alle drei stehen in der Spezifikation von
     * GitHub:
     *
     *   `@acme/kasse`       — ein Team. Der Bereich davor („acme") ist die
     *                         Organisation bei GitHub und hier bedeutungslos;
     *                         gemeint ist der Name dahinter.
     *   `@anna`             — ein Konto. Sein Name muss hier einem Konto oder
     *                         einem Team entsprechen — ein GitHub-Kürzel, das
     *                         niemandem gehört, wird von der Prüfung darüber
     *                         aussortiert.
     *   `anna@example.com`  — eine Adresse. Die verlässlichste Form, weil sie
     *                         in beiden Systemen dasselbe bedeutet.
     */
    private static function owner(string $part): ?string
    {
        $part = trim($part);

        if ($part === '') {
            return null;
        }

        if (! str_starts_with($part, '@')) {
            // Alles ohne `@` am Anfang ist eine Adresse — oder es ist Unsinn,
            // und dann findet ihn die Auflösung.
            return $part;
        }

        $name = mb_substr($part, 1);

        if ($name === '') {
            return null;
        }

        if (str_contains($name, '/')) {
            $team = mb_substr($name, mb_strrpos($name, '/') + 1);

            return $team === '' ? null : IssueAssignee::TEAM_PREFIX.$team;
        }

        return $name;
    }

    /**
     * Ein CODEOWNERS-Muster als Pfad-Muster dieser Anwendung.
     *
     * Die Unterschiede sind klein und wären einzeln verzeihlich; zusammen
     * ergäben sie eine importierte Liste, die etwas anderes bedeutet als die
     * Datei:
     *
     * - **Der führende Schrägstrich meint das Wurzelverzeichnis** des
     *   Repositories. Hier gibt es kein Wurzelverzeichnis, sondern Pfade aus
     *   einem Stacktrace — er wird abgeschnitten, und das Muster gilt damit
     *   relativ (siehe {@see Ownership} zur Auflösung ohne führenden
     *   Platzhalter).
     * - **Ein Verzeichnis endet auf `/`** und meint alles darin. Ohne den
     *   angehängten Platzhalter träfe es genau den Verzeichnisnamen und damit
     *   keine einzige Datei.
     * - **`**` und `*` sind hier dasselbe.** Unsere Muster kennen keine
     *   Verzeichnisgrenze, `*` überspringt sie ohnehin — die doppelte
     *   Schreibweise stehen zu lassen ergäbe `.*.*` und damit ein Muster, das
     *   dasselbe langsamer tut.
     */
    private static function pattern(string $pattern): ?string
    {
        $pattern = str_replace('\\', '/', trim($pattern));
        $pattern = ltrim($pattern, '/');

        while (str_contains($pattern, '**')) {
            $pattern = str_replace('**', '*', $pattern);
        }

        if ($pattern === '' || $pattern === '*') {
            // `*` allein ist in einer CODEOWNERS-Datei die Auffangzeile für das
            // ganze Repository. Sie wird bewusst übernommen — als Muster, das
            // auf jeden Pfad passt, ist sie hier genauso gemeint.
            return $pattern === '' ? null : '*';
        }

        if (str_ends_with($pattern, '/')) {
            $pattern .= '*';
        }

        return mb_substr($pattern, 0, OwnershipRule::PATTERN_LIMIT);
    }

    /**
     * @return array{line: int, text: string, reason: string}
     */
    private static function skip(int $line, string $text, string $reason): array
    {
        return [
            'line' => $line,
            // Gekürzt, weil der Text nur zum Wiedererkennen dient — eine
            // dreihundert Zeichen lange Zeile in einer Meldung hilft niemandem.
            'text' => mb_substr($text, 0, 120),
            'reason' => $reason,
        ];
    }

    /**
     * Die Zeilen des Textes, beginnend bei 1 — die Nummer ist Teil der Meldung.
     *
     * @return array<int, string>
     */
    private static function lines(string $contents): array
    {
        $lines = preg_split('/\R/u', $contents);

        if (! is_array($lines)) {
            return [];
        }

        $numbered = [];

        foreach (array_slice($lines, 0, self::MAX_LINES) as $index => $line) {
            $numbered[$index + 1] = is_string($line) ? $line : '';
        }

        return $numbered;
    }
}
