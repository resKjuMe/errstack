<?php

namespace App\Support\Issues;

use App\Enums\IssueStatus;
use App\Models\EventGroup;
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
            // Ob die Stufe von Hand steht (S11). Die Oberfläche braucht das
            // nicht zum Anzeigen, sondern für die Auskunft, ob die Ableitung
            // noch mitredet — „hoch" von der Automatik und „hoch, weil ich das
            // sage" sind zwei verschiedene Aussagen.
            'priorityLocked' => $issue->priority_locked,
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

            // Der Rückfall (S8): der Eintrag ist offen, aber nicht, weil jemand
            // ihn geöffnet hat. Er steht neben dem Zustand und nicht in ihm —
            // „wieder aufgetreten" beantwortet, wie er hierher kam, und nicht,
            // woran er ist.
            'regression' => self::regression($issue),
            // Wer sich kümmert (S7), und ob der Eintrag noch zur Prüfung liegt.
            // Beides gehört in den Kopf und nicht in den Verlauf: der Verlauf
            // sagt, **wann** zugewiesen wurde, der Kopf sagt, **wer** es jetzt
            // ist — und danach fragt, wer die Seite aufschlägt.
            'assignee' => self::assignee($issue),
            'forReview' => $issue->for_review_at !== null,

            // Was **diesem** Betrachter an dem Eintrag gehört. Nicht am Eintrag
            // gespeichert, sondern je Person — eine Spalte am Eintrag könnte nur
            // die Meinung des Letzten festhalten.
            'bookmarked' => $viewer !== null && $issue->bookmarkedBy()->whereKey($viewer->id)->exists(),
            'subscribed' => $viewer !== null && $issue->subscribers()->whereKey($viewer->id)->exists(),

            // Woraus dieser Eintrag besteht und wozu er gehört (S9). Beides ist
            // leer bzw. `null`, solange niemand von Hand zusammengeführt hat —
            // der Regelfall, und deshalb kein eigener Abschnitt in der Anzeige.
            'merged' => self::merged($issue),
            'mergedInto' => self::mergedInto($issue),
        ];
    }

    /**
     * Die Untergruppen: die Einträge, die diesem beigetreten sind.
     *
     * Sie stehen mit ihren **eigenen** Zahlen da und nicht mit einem Anteil am
     * Ganzen. Das ist die Auskunft, wegen der jemand hinschaut: „diese
     * Untergruppe ist zehnmal aufgetreten, jene zehntausendmal" beantwortet die
     * Frage, ob das Zusammenführen richtig war — ein Anteil am Kopf täte das
     * nicht.
     *
     * Die Fingerabdrücke gehören dazu, denn sie sind der Grund, warum es zwei
     * Einträge gab. Gezeigt wird ihr Anfang: der ganze Streuwert ist eine lange
     * Zeichenkette ohne Aussage, die ersten zwölf Zeichen unterscheiden
     * zuverlässig genug.
     *
     * @return list<array<string, mixed>>
     */
    private static function merged(Issue $issue): array
    {
        return $issue->mergedSources
            ->sortByDesc('times_seen')
            ->values()
            ->map(fn (Issue $source): array => [
                'id' => $source->id,
                'title' => $source->title ?? $source->culprit ?? __('issues.list.untitled'),
                'culprit' => $source->culprit,
                'type' => $source->type,
                'timesSeen' => $source->times_seen,
                'timesSeenLabel' => Formats::number($source->times_seen),
                'firstSeenLabel' => Formats::dateTime($source->first_seen),
                'lastSeenLabel' => Formats::dateTime($source->last_seen),
                'href' => route('issues.show', $source),
                'unmergeHref' => route('issues.merge.destroy', $source),
                'fingerprints' => $source->groups
                    ->map(fn (EventGroup $group): string => mb_substr($group->fingerprint, 0, 12))
                    ->all(),
            ])
            ->all();
    }

    /**
     * Der Eintrag, dem dieser beigetreten ist.
     *
     * Ein beigetretener Eintrag bleibt aufrufbar — Lesezeichen und alte Links
     * zeigen weiter auf ihn —, aber seine Zahlen stehen still: gezählt wird ab
     * dem Beitritt am Kopf. Ohne diesen Hinweis sähe das aus wie ein Fehler, der
     * aufgehört hat.
     *
     * @return array{id: int, title: string, href: string}|null
     */
    private static function mergedInto(Issue $issue): ?array
    {
        $head = $issue->mergedInto;

        if (! $head instanceof Issue) {
            return null;
        }

        return [
            'id' => $head->id,
            'title' => $head->title ?? $head->culprit ?? __('issues.list.untitled'),
            'href' => route('issues.show', $head),
        ];
    }

    /**
     * Der Zuständige samt Zeitpunkt und dem, der ihn eingetragen hat.
     *
     * `term` fährt mit, damit die Auswahlliste beim Öffnen den jetzigen
     * Zuständigen kennt, ohne ihn aus dem Namen zurückrechnen zu müssen — bei
     * zwei gleichnamigen Personen ginge das nicht.
     *
     * @return array{label: string, term: string, kind: string, at: string|null, atLabel: string|null, by: string|null}|null
     */
    private static function assignee(Issue $issue): ?array
    {
        $assignee = match (true) {
            $issue->assignedTeam !== null => IssueAssignee::forTeam($issue->assignedTeam),
            $issue->assignedUser !== null => IssueAssignee::forUser($issue->assignedUser),
            default => null,
        };

        if ($assignee === null) {
            return null;
        }

        return [
            'label' => $assignee->label(),
            'term' => $assignee->term(),
            'kind' => $assignee->kind(),
            'at' => $issue->assigned_at?->toIso8601String(),
            'atLabel' => Formats::dateTime($issue->assigned_at),
            'by' => $issue->assignedBy?->name,
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
     * Der Rückfall — `null`, solange der Eintrag keiner ist.
     *
     * Die Version steht mit dabei, weil sie die eigentliche Auskunft ist: „ist
     * wieder aufgetreten" beantwortet noch nicht, ob der Fix es nie in eine
     * Auslieferung geschafft hat oder ob er es getan hat und trotzdem nicht
     * hält. `null` bleibt sie, wenn die Meldung keine Version trug — der
     * Regelfall bei einem SDK ohne `release`-Angabe.
     *
     * @return array{at: string|null, atLabel: string|null, release: string|null}|null
     */
    private static function regression(Issue $issue): ?array
    {
        if (! $issue->hasRegressed()) {
            return null;
        }

        return [
            'at' => $issue->regressed_at?->toIso8601String(),
            'atLabel' => Formats::dateTime($issue->regressed_at),
            'release' => $issue->regressedInRelease?->version,
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
