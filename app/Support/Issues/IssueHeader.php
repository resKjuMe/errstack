<?php

namespace App\Support\Issues;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
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
    public static function present(Issue $issue, ?User $viewer = null): array
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

            // Woran der Zustand hängt (S6). Der Zustand allein sagt „erledigt";
            // erst die Bedingung sagt, ob das heißt „behoben", „behoben in
            // 1.4.2" oder „behoben, sobald ausgeliefert wird" — und das ist der
            // Unterschied, wegen dessen der Eintrag morgen wieder auftaucht oder
            // nicht.
            'resolution' => self::resolution($issue),
            'ignore' => self::ignore($issue),

            // Was **diesem** Betrachter an dem Eintrag gehört. Nicht am Eintrag
            // gespeichert, sondern je Person — eine Spalte am Eintrag könnte nur
            // die Meinung des Letzten festhalten.
            'bookmarked' => $viewer !== null && $issue->bookmarkedBy()->whereKey($viewer->id)->exists(),
            'subscribed' => $viewer !== null && $issue->subscribers()->whereKey($viewer->id)->exists(),
        ];
    }

    /**
     * Woraufhin der Eintrag als erledigt gilt — `null`, solange er offen ist.
     *
     * @return array{at: string|null, atLabel: string|null, by: string|null, release: string|null, nextRelease: bool}|null
     */
    private static function resolution(Issue $issue): ?array
    {
        if ($issue->status !== IssueStatus::Resolved) {
            return null;
        }

        return [
            'at' => $issue->resolved_at?->toIso8601String(),
            'atLabel' => Formats::dateTime($issue->resolved_at),
            'by' => $issue->resolvedBy?->name,
            'release' => $issue->resolvedInRelease?->version,
            'nextRelease' => $issue->resolved_in_next_release,
        ];
    }

    /**
     * Die laufende Stummschaltung samt Bedingung und Fortschritt.
     *
     * Der Fortschritt („41 von 100") steht dabei ausdrücklich mit dabei: eine
     * Bedingung, deren Stand man nicht sieht, ist von „dauerhaft" nicht zu
     * unterscheiden — und dann fragt sich jeder, warum der Fehler nicht
     * wiederkommt.
     *
     * @return array{at: string|null, atLabel: string|null, by: string|null, condition: string, progress: array{done: int, total: int}|null}|null
     */
    private static function ignore(Issue $issue): ?array
    {
        if ($issue->status !== IssueStatus::Ignored) {
            return null;
        }

        $condition = IgnoreCondition::fromIssue($issue);

        return [
            'at' => $issue->ignored_at?->toIso8601String(),
            'atLabel' => Formats::dateTime($issue->ignored_at),
            'by' => $issue->ignoredBy?->name,
            'condition' => self::conditionLabel($condition),
            'progress' => match (true) {
                $condition->users !== null => [
                    'done' => max(0, $issue->users_seen - $condition->usersSeenAtStart),
                    'total' => $condition->users,
                ],
                $condition->count !== null => [
                    'done' => max(0, $issue->times_seen - $condition->timesSeenAtStart),
                    'total' => $condition->count,
                ],
                default => null,
            },
        ];
    }

    private static function conditionLabel(IgnoreCondition $condition): string
    {
        return match (true) {
            $condition->users !== null => __('issues.actions.condition.users', [
                'count' => Formats::number($condition->users),
            ]),
            $condition->count !== null && $condition->windowMinutes !== null => __('issues.actions.condition.count_window', [
                'count' => Formats::number($condition->count),
                'minutes' => Formats::number($condition->windowMinutes),
            ]),
            $condition->count === 1 => __('enums.issue_ignore_mode.until_recurrence'),
            $condition->count !== null => __('issues.actions.condition.count', [
                'count' => Formats::number($condition->count),
            ]),
            default => __('enums.issue_ignore_mode.forever'),
        };
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
