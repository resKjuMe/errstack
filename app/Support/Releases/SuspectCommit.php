<?php

namespace App\Support\Releases;

use App\Models\Commit;
use App\Models\CommitFile;

/**
 * Ein Commit, der diesen Fehler verursacht haben könnte — samt der Begründung,
 * warum er unter Verdacht steht.
 *
 * Die Begründung wird mitgeführt und nicht später neu hergeleitet: sie ist das
 * eigentliche Ergebnis. „Dieser Commit ist verdächtig" ohne den Grund ist ein
 * Orakelspruch, und wer ihm nicht glaubt, kann ihn nicht prüfen — mit
 * `app/Http/Kernel.php, Zeile 42 liegt in einer geänderten Stelle` dagegen
 * schon.
 */
final class SuspectCommit
{
    /**
     * @param  Commit  $commit  der Verdächtige
     * @param  CommitFile  $file  die Datei, über die er auffiel
     * @param  string|null  $frame  der Pfad, wie er im Stacktrace steht
     * @param  int|null  $line  die Zeile aus dem Stacktrace
     * @param  bool  $matchedLine  lag diese Zeile in einer geänderten Stelle?
     * @param  int  $score  wie schwer der Verdacht wiegt (nur zum Sortieren)
     */
    public function __construct(
        public readonly Commit $commit,
        public readonly CommitFile $file,
        public readonly ?string $frame,
        public readonly ?int $line,
        public readonly bool $matchedLine,
        public readonly int $score,
    ) {}

    /**
     * Wer als Zuständiger in Frage kommt — oder niemand.
     *
     * Nur ein zugeordnetes Konto, nicht die Adresse aus dem Commit: zuständig
     * wird man in dieser Anwendung, und eine Adresse, hinter der kein Konto
     * steht, ließe sich weder benachrichtigen noch anschreiben.
     */
    public function authorId(): ?int
    {
        return $this->commit->author_id;
    }
}
