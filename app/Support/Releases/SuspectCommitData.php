<?php

namespace App\Support\Releases;

use App\Support\Formats;

/**
 * Die Verdächtigen (R4), fertig für die Oberfläche.
 *
 * Getrennt von {@see SuspectCommits}, weil es zwei verschiedene Dinge sind: das
 * eine ist der Abgleich und wird auch beim Aufnehmen gebraucht, wo niemand
 * hinsieht; das andere ist die Darstellung. Beides in einer Klasse hieße, dass
 * der Aufnahme-Weg Datumsformate und Übersetzungen mitschleppt, die er nie
 * benutzt.
 *
 * **Die Begründung wird nicht ausformuliert, sondern zerlegt weitergegeben.**
 * Pfad, Zeile und die Frage „lag sie in einer geänderten Stelle?" gehen als
 * Angaben hinaus, den Satz baut die Oberfläche — sie ist die Stelle, die
 * übersetzt.
 */
final class SuspectCommitData
{
    /**
     * @param  list<SuspectCommit>  $suspects
     * @return list<array<string, mixed>>
     */
    public static function present(array $suspects): array
    {
        return array_map(static function (SuspectCommit $suspect): array {
            $commit = $suspect->commit;
            $repository = $commit->repository;

            // Über den Fremdschlüssel und nicht über die Beziehung: ob es ein
            // Konto gibt, steht am Commit selbst — dieselbe Unterscheidung wie
            // bei der Commit-Liste einer Auslieferung ({@see ReleaseDetail}).
            $account = $commit->author_id === null ? null : $commit->author;

            return [
                'id' => $commit->id,
                'sha' => $commit->sha,
                'shortSha' => $commit->shortSha(),
                'title' => $commit->title(),
                'href' => $repository?->commitUrl($commit->sha),
                'committedAtLabel' => Formats::dateTime($commit->committed_at),
                'repository' => $repository === null ? null : ['name' => $repository->name],
                'author' => [
                    'name' => $account === null ? $commit->author_name : $account->name,
                    'email' => $commit->author_email,
                    'isMember' => $account !== null,
                ],
                // Warum dieser Commit hier steht. Ohne das ist die Liste eine
                // Behauptung, die sich nicht nachprüfen lässt.
                'reason' => [
                    'path' => $suspect->file->path,
                    'change' => $suspect->file->change_type->value,
                    'frame' => $suspect->frame,
                    'line' => $suspect->line,
                    'matchedLine' => $suspect->matchedLine,
                ],
            ];
        }, $suspects);
    }
}
