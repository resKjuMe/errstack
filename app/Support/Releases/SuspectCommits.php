<?php

namespace App\Support\Releases;

use App\Models\CommitFile;
use App\Models\Event;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Welche Commits diesen Fehler verursacht haben könnten.
 *
 * Die Frage, die nach jeder Auslieferung als Erstes gestellt wird — und die
 * bisher von Hand im Git-Log beantwortet werden musste. Sie hat eine
 * mechanische Antwort: der Stacktrace nennt Dateien und Zeilen, die
 * Auslieferung nennt ihre Commits (R2), und die Commits nennen die Dateien und
 * Zeilen, die sie angefasst haben. Wo sich beides trifft, steht ein
 * Verdächtiger.
 *
 * **Der Abgleich geht über Datei *und* Zeile.** Nur über den Dateinamen wäre er
 * fast wertlos: in einer Auslieferung fassen ein halbes Dutzend Commits
 * dieselbe große Datei an, und alle sechs kämen mit demselben Gewicht heraus.
 * Die Zeile aus dem Stacktrace entscheidet, welcher davon die Stelle angefasst
 * hat, an der es knallt. Wo die Zeilenangabe fehlt — sentry-cli schickt sie
 * nicht —, zählt der Treffer über den Pfad weiter, nur eben schwächer; die
 * Antwort wird dadurch unschärfer, aber nicht falsch.
 *
 * **Betroffen ist, wo der Fehler auftauchte, nicht wo er zuletzt gesehen
 * wurde.** Verglichen wird gegen die Auslieferung des ersten Auftretens und —
 * falls es eine gibt — gegen die des Rückfalls (S8). Die Version des letzten
 * Auftretens ist dagegen nur die von heute Morgen: sie enthält den Fehler,
 * verursacht hat ihn eine frühere.
 *
 * **Ohne verbundenes Repository kommt eine leere Liste heraus.** Kein Fehler,
 * keine Ausnahme, kein leerer Kasten mit Entschuldigung — wer keine Commits
 * übergibt, bekommt den Bereich nicht zu sehen.
 */
final class SuspectCommits
{
    /**
     * Höchstens drei Verdächtige.
     *
     * Eine Liste, die zehn Commits „möglich" nennt, ist die Arbeit von vorher
     * mit zusätzlichen Schritten. Drei sind wenige genug, um sie tatsächlich
     * anzusehen — und wenn der richtige nicht darunter ist, war der Abgleich
     * ohnehin nicht der Weg.
     */
    public const LIMIT = 3;

    /**
     * So viele Stellen des Stacktrace werden verglichen.
     *
     * Von innen nach außen: die innerste Stelle ist die, an der es knallte, und
     * ab einer gewissen Tiefe steht dort nur noch der Rahmen, der die Anwendung
     * gestartet hat. Ein Stacktrace mit zweihundert Stellen (eine Rekursion)
     * würde sonst zweihundert Dateinamen in eine Abfrage schreiben.
     */
    private const MAX_FRAMES = 40;

    /**
     * Und so viele Dateizeilen werden dafür höchstens gelesen.
     *
     * Die Grenze greift bei einer Auslieferung, die eine viel angefasste Datei
     * enthält — sie kostet dann die hinteren Treffer, aber keine Antwortzeit.
     */
    private const MAX_CANDIDATES = 500;

    /**
     * Die Verdächtigen zu einem Fehler, anhand einer bestimmten Meldung.
     *
     * Die Meldung wird übergeben und nicht hier gesucht: die Detailseite zeigt
     * **eine** davon, und die Verdächtigen sollen zu dem Stacktrace passen, der
     * darüber steht. Beim Aufnehmen ist es die gerade eingetroffene Meldung.
     *
     * @return list<SuspectCommit>
     */
    public static function forEvent(Issue $issue, ?Event $event, int $limit = self::LIMIT): array
    {
        if ($event === null) {
            return [];
        }

        $releaseIds = self::releaseIds($issue);
        $frames = self::frames($event);

        if ($releaseIds === [] || $frames === []) {
            return [];
        }

        $candidates = self::candidates($releaseIds, $frames);

        if ($candidates->isEmpty()) {
            return [];
        }

        $best = [];

        foreach ($candidates as $file) {
            $suspect = self::judge($file, $frames);

            if ($suspect === null) {
                continue;
            }

            // Je Commit bleibt der stärkste Treffer stehen. Ein Commit, der
            // drei Dateien des Stacktrace angefasst hat, ist ein Verdächtiger
            // und nicht drei — und die Begründung soll die beste sein, nicht
            // die erste.
            $known = $best[$suspect->commit->id] ?? null;

            if ($known === null || $suspect->score > $known->score) {
                $best[$suspect->commit->id] = $suspect;
            }
        }

        $ranked = array_values($best);

        usort($ranked, static function (SuspectCommit $a, SuspectCommit $b): int {
            // Bei gleichem Gewicht der jüngere zuerst: unter zwei gleich
            // verdächtigen Änderungen an derselben Stelle ist die spätere die
            // wahrscheinlichere Ursache.
            return $b->score <=> $a->score
                ?: ($b->commit->committed_at?->getTimestamp() ?? 0) <=> ($a->commit->committed_at?->getTimestamp() ?? 0)
                ?: strcmp($a->commit->sha, $b->commit->sha);
        });

        return array_slice($ranked, 0, max(1, $limit));
    }

    /**
     * Die Auslieferungen, gegen die verglichen wird.
     *
     * @return list<int>
     */
    private static function releaseIds(Issue $issue): array
    {
        return array_values(array_unique(array_filter([
            $issue->first_release_id,
            $issue->regressed_in_release_id,
        ])));
    }

    /**
     * Der Stacktrace als Liste aus Pfad und Zeile, innerste Stelle zuerst.
     *
     * **Zurückübersetzt, wenn es eine Übersetzung gibt** (R5): in einem
     * gebündelten JavaScript heißt jede Stelle `main.a3f2.js`, und die steht in
     * keinem Repository. Erst die Rückübersetzung nennt `src/checkout/Cart.tsx`
     * — und damit einen Pfad, den ein Commit angefasst haben kann.
     *
     * Nur die letzte Ausnahme der Ursachenkette, wie überall sonst auch: sie ist
     * die, die die Anwendung gesehen hat.
     *
     * @return list<array{path: list<string>, line: int|null, inApp: bool, label: string}>
     */
    private static function frames(Event $event): array
    {
        $exceptions = $event->symbolication?->exceptions;

        if (is_array($exceptions) && $exceptions !== []) {
            $last = $exceptions[array_key_last($exceptions)];
            $raw = is_array($last['frames'] ?? null) ? $last['frames'] : [];
        } else {
            $raw = $event->frames();
        }

        $frames = [];

        // Von hinten: die letzte Stelle eines Stacktrace ist die innerste.
        foreach (array_reverse($raw) as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            // Beide Felder in dieser Reihenfolge, und ein leeres zählt als
            // fehlend: manche SDKs schicken `filename` als leere Zeichenkette
            // und den brauchbaren Weg in `abs_path`. Ein bloßes `??` würde bei
            // der leeren Zeichenkette stehen bleiben und die Stelle verwerfen.
            $label = self::text($frame['filename'] ?? null) ?? self::text($frame['abs_path'] ?? null);

            if ($label === null) {
                continue;
            }

            $segments = self::segments($label);

            if ($segments === []) {
                continue;
            }

            $frames[] = [
                'path' => $segments,
                'line' => is_int($frame['lineno'] ?? null) ? $frame['lineno'] : null,
                'inApp' => ($frame['in_app'] ?? false) === true,
                'label' => $label,
            ];

            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        return $frames;
    }

    /**
     * Eine Angabe, die nur dann eine ist, wenn etwas darin steht.
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Ein Pfad in seine Bestandteile, unabhängig davon, wie er geschrieben ist.
     *
     * Dieselbe Datei heißt je nach Absender `/var/www/app/Kernel.php`,
     * `app\Kernel.php` oder `app:///app/Kernel.php`. Verglichen wird deshalb
     * nicht die Zeichenkette, sondern die Folge der Bestandteile von hinten
     * (siehe {@see overlap()}) — das ist zugleich die Antwort darauf, dass im
     * Repository ein relativer Pfad steht und im Stacktrace ein absoluter.
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $path = str_replace('\\', '/', trim($path));

        // Ein Schema davor (`app:///`, `webpack:///`, `file:///`) und die
        // Angaben dahinter (`?v=3`, `#foo`) gehören nicht zum Pfad.
        $path = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $path);
        $path = Str::before(Str::before($path, '?'), '#');

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Die Dateizeilen der betroffenen Auslieferungen, die überhaupt in Frage
     * kommen.
     *
     * Eingegrenzt wird in der Datenbank und über den **Dateinamen**, nicht über
     * den ganzen Pfad: der ganze Pfad passt fast nie (im Repository steht
     * `app/Kernel.php`, im Stacktrace `/var/www/app/Kernel.php`), der Dateiname
     * dagegen immer. Was danach noch übrig ist, prüft
     * {@see overlap()} genau — die Abfrage ist die Vorauswahl, nicht die
     * Entscheidung.
     *
     * @param  list<int>  $releaseIds
     * @param  list<array{path: list<string>, line: int|null, inApp: bool, label: string}>  $frames
     * @return Collection<int, CommitFile>
     */
    private static function candidates(array $releaseIds, array $frames): Collection
    {
        $names = [];

        foreach ($frames as $frame) {
            $names[$frame['path'][count($frame['path']) - 1]] = true;
        }

        return CommitFile::query()
            ->whereIn('commit_id', fn ($query) => $query
                ->select('commit_id')
                ->from('release_commit')
                ->whereIn('release_id', $releaseIds))
            ->where(function ($query) use ($names) {
                foreach (array_keys($names) as $name) {
                    // Ohne Maskierung der Platzhalter `%` und `_`: sie kommen in
                    // Dateinamen vor (`_helpers.php`) und würden dann etwas zu
                    // viel einsammeln. Das ist hier folgenlos — die genaue
                    // Prüfung kommt danach im Speicher, und die Abfrage soll
                    // eher zu viel als zu wenig finden.
                    $query->orWhere('path', 'like', '%'.$name);
                }
            })
            // Der Commit und sein Autor stehen in der Begründung; ohne das
            // Nachladen wären es zwei Abfragen je Zeile.
            ->with(['commit.repository', 'commit.author'])
            ->limit(self::MAX_CANDIDATES)
            ->get();
    }

    /**
     * Wiegt eine Dateizeile gegen den Stacktrace ab.
     *
     * Vier Fragen, und ihre Gewichte sagen, was zählt: **die Zeile** wiegt am
     * schwersten — ein Commit, der genau die Zeile angefasst hat, an der es
     * knallt, ist etwas anderes als einer, der irgendwo in derselben Datei war.
     * Danach kommt, ob die Stelle im **eigenen** Code liegt: ein Treffer in
     * einer Bibliothek nennt selten die Ursache. Dann, **wie weit innen** die
     * Stelle steht, und zuletzt, **wie genau** der Pfad passt — `Cart.tsx`
     * allein ist ein schwächeres Indiz als `src/checkout/Cart.tsx`.
     *
     * @param  list<array{path: list<string>, line: int|null, inApp: bool, label: string}>  $frames
     */
    private static function judge(CommitFile $file, array $frames): ?SuspectCommit
    {
        $repoPath = self::segments($file->path);

        if ($repoPath === [] || $file->commit === null) {
            return null;
        }

        $best = null;

        foreach ($frames as $rank => $frame) {
            $overlap = self::overlap($frame['path'], $repoPath);

            if ($overlap === 0) {
                continue;
            }

            $matchedLine = $file->touchesLine($frame['line']);

            $score = 0;
            $score += $matchedLine ? 60 : 0;
            $score += $frame['inApp'] ? 15 : 0;
            $score += max(1, 20 - $rank);
            $score += min($overlap, 5) * 4;

            if ($best === null || $score > $best->score) {
                $best = new SuspectCommit(
                    commit: $file->commit,
                    file: $file,
                    frame: $frame['label'],
                    line: $frame['line'],
                    matchedLine: $matchedLine,
                    score: $score,
                );
            }
        }

        return $best;
    }

    /**
     * Wie viele Bestandteile die beiden Pfade **von hinten** gemeinsam haben.
     *
     * `app/Http/Kernel.php` und `/var/www/app/Http/Kernel.php` haben drei —
     * genug, um dieselbe Datei zu meinen. `Kernel.php` und
     * `vendor/laravel/…/Kernel.php` haben einen: derselbe Dateiname, und mehr
     * ist damit auch nicht behauptet.
     *
     * @param  list<string>  $frame
     * @param  list<string>  $repository
     */
    private static function overlap(array $frame, array $repository): int
    {
        $shared = 0;
        $a = count($frame) - 1;
        $b = count($repository) - 1;

        while ($a >= 0 && $b >= 0 && $frame[$a] === $repository[$b]) {
            $shared++;
            $a--;
            $b--;
        }

        return $shared;
    }
}
