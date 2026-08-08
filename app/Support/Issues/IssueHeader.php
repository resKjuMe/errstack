<?php

namespace App\Support\Issues;

use App\Models\EventGroup;
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
