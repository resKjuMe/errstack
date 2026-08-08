<?php

namespace App\Support\Issues;

use App\Models\Issue;
use App\Models\Project;
use App\Support\Formats;

/**
 * Der Kopf der Detailseite: was über den Fehler als Ganzes bekannt ist.
 *
 * **Die Zahlen kommen aus dem Eintrag, nicht aus den Meldungen.** Häufigkeit,
 * Betroffene und die beiden Zeitpunkte stehen als Zähler am Eintrag; sie über
 * die Einzelereignisse zu rechnen wäre dieselbe Auskunft zum Preis eines
 * Tabellendurchlaufs — und genau die Zusage, die die Liste (S1) schon gibt,
 * gilt hier weiter. Die Seite lädt deshalb ein Ereignis und einen Datensatz.
 */
final class IssueHeader
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            // Eine bloße Meldung ohne Ausnahme hat keinen Titel; die
            // Fehlerstelle ist dann die aussagekräftigste Angabe, die es gibt.
            'title' => $issue->title ?? $issue->culprit ?? __('issues.list.untitled'),
            'culprit' => $issue->culprit,
            'type' => $issue->type,
            'level' => $issue->level->value,
            'levelLabel' => $issue->level->label(),
            'status' => $issue->status->value,
            'statusLabel' => $issue->status->label(),
            'priority' => $issue->priority->value,
            'priorityLabel' => $issue->priority->label(),
            'timesSeen' => $issue->times_seen,
            'timesSeenLabel' => Formats::number($issue->times_seen),
            'usersSeen' => $issue->users_seen,
            'usersSeenLabel' => Formats::number($issue->users_seen),
            'firstSeen' => $issue->first_seen->toIso8601String(),
            'firstSeenLabel' => Formats::dateTime($issue->first_seen),
            'lastSeen' => $issue->last_seen->toIso8601String(),
            'lastSeenLabel' => Formats::dateTime($issue->last_seen),
            'project' => self::project($issue),
        ];
    }

    /**
     * @return array{name: string, slug: string, href: string}|null
     */
    private static function project(Issue $issue): ?array
    {
        $project = $issue->project;

        if (! $project instanceof Project) {
            return null;
        }

        return [
            'name' => $project->name,
            'slug' => $project->slug,
            'href' => route('projects.show', [$project->organization, $project]),
        ];
    }
}
