<?php

namespace App\Support\Releases;

use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\Deploy;
use App\Models\Release;
use App\Support\Formats;
use App\Support\Search\SearchQuery;

/**
 * Die Detailseite einer Auslieferung: was drinsteckt.
 *
 * Sie beantwortet die Frage, die unmittelbar auf „es wurde ausgeliefert" folgt
 * — **was** wurde ausgeliefert, und von wem. Die Liste (R1) sagt, dass es die
 * Version gibt und wie viele Fehler mit ihr dazugekommen sind; hier stehen die
 * Änderungen selbst.
 *
 * **Noch nicht hier: der Vergleich zur Vorversion** samt Übersichtszahlen — das
 * ist R8 und braucht die Ordnung zwischen zwei Auslieferungen, nicht den Inhalt
 * einer. Und die Gesundheit (abgestürzte Sitzungen) ist R7. Diese Seite kommt
 * mit dem aus, was in der Auslieferung selbst steht.
 */
final class ReleaseDetail
{
    /**
     * Höchstens so viele Commits stehen auf der Seite.
     *
     * Der Regelfall sind ein paar Dutzend. Der Ausreißer ist die erste
     * Auslieferung nach einer langen Pause oder ein zusammengeführter
     * Entwicklungszweig — dort sind es Tausende, jeder mit seiner Dateiliste,
     * und die Seite wäre weder zu laden noch zu lesen.
     *
     * Abgeschnitten wird deshalb, aber **sichtbar**: die Oberfläche nennt die
     * volle Zahl daneben. Eine stillschweigend gekürzte Liste sähe aus wie die
     * ganze — und wer nach einem bestimmten Commit sucht, käme zu dem Schluss,
     * er sei nicht ausgeliefert worden.
     */
    public const MAX_COMMITS = 250;

    /**
     * Die Auslieferung samt ihrer Commits, fertig für die Oberfläche.
     *
     * Alles in **einem** Satz Abfragen: die Commits mit ihrem Repository, ihren
     * Dateien und dem zugeordneten Konto. Ohne das Vorausladen wäre jede
     * Commit-Zeile drei weitere Abfragen, und dreihundert Commits sind für eine
     * Auslieferung nichts Ungewöhnliches.
     *
     * @return array<string, mixed>
     */
    public static function present(Release $release): array
    {
        $release->loadMissing('project.organization');

        // Die Auslieferungen dieser Version (R3). Sie stehen auf derselben
        // Seite wie ihr Inhalt, weil dort die Frage entsteht, für die es sie
        // gibt: „was steckt drin" und „seit wann ist das draußen" sind zwei
        // Hälften derselben Auskunft.
        $deploys = $release->deploys()->with('environment')->get();

        $total = $release->commits()->count();

        $commits = $release->commits()
            ->with(['repository', 'files', 'author:id,name,email'])
            ->limit(self::MAX_COMMITS)
            ->get();

        return [
            'release' => [
                'id' => $release->id,
                'version' => $release->version,
                'ref' => $release->ref,
                'url' => $release->url,
                'isOrdered' => $release->sort_major !== null,
                'releasedAtLabel' => Formats::dateTime($release->released_at),
                'firstEventAtLabel' => Formats::dateTime($release->first_event_at),
                'lastEventAtLabel' => Formats::dateTime($release->last_event_at),
                'project' => [
                    'name' => $release->project?->name,
                    'href' => $release->project === null
                        ? null
                        : route('projects.show', [$release->project->organization, $release->project]),
                ],
                // Der Weg von der Version in die Fehlerliste — dieselbe Frage
                // wie in der Übersicht: „was ist mit dieser Auslieferung
                // dazugekommen?"
                'issuesHref' => route('issues.index', [
                    'q' => SearchQuery::term('firstRelease', $release->version),
                ]),
                'indexHref' => route('releases.index'),
            ],
            'deploys' => $deploys->map(fn (Deploy $deploy): array => self::deploy($deploy))->all(),
            'commits' => $commits->map(fn (Commit $commit): array => self::commit($commit))->all(),
            // Die volle Zahl und nicht die der gezeigten Zeilen: sie ist die
            // Auskunft über die Auslieferung, die Liste darunter nur ein
            // Ausschnitt davon.
            'commitsLabel' => Formats::number($total),
            'commitsTruncated' => $total > $commits->count(),
            'commitsShownLabel' => Formats::number($commits->count()),
        ];
    }

    /**
     * Eine Auslieferung.
     *
     * Die Dauer steht nur da, wo ein Beginn übergeben wurde — sie ist die
     * einzige Angabe hier, die aus zwei anderen entsteht, und ohne den Beginn
     * wäre sie eine Behauptung.
     *
     * @return array<string, mixed>
     */
    private static function deploy(Deploy $deploy): array
    {
        return [
            'id' => $deploy->id,
            'label' => $deploy->label(),
            'environment' => $deploy->environment?->name,
            'url' => $deploy->url,
            'atLabel' => Formats::dateTime($deploy->finished_at),
            'startedAtLabel' => Formats::dateTime($deploy->started_at),
        ];
    }

    /**
     * Eine Commit-Zeile.
     *
     * @return array<string, mixed>
     */
    private static function commit(Commit $commit): array
    {
        $repository = $commit->repository;

        return [
            'id' => $commit->id,
            'sha' => $commit->sha,
            'shortSha' => $commit->shortSha(),
            'title' => $commit->title(),
            'body' => $commit->body(),
            'committedAtLabel' => Formats::dateTime($commit->committed_at),
            'href' => $repository->commitUrl($commit->sha),
            'repository' => [
                'name' => $repository->name,
                'url' => $repository->url,
            ],
            'author' => self::author($commit),
            'files' => $commit->files
                ->sortBy(fn (CommitFile $file): string => $file->path)
                ->values()
                ->map(fn (CommitFile $file): array => [
                    'id' => $file->id,
                    'path' => $file->path,
                    'change' => $file->change_type->value,
                    'changeLabel' => $file->change_type->label(),
                ])->all(),
        ];
    }

    /**
     * Der Autor, wie er angezeigt wird.
     *
     * Drei Fälle, und sie zu unterscheiden ist der Grund, warum die Zuordnung
     * überhaupt stattfindet: ein Commit von einem Mitglied nennt dessen Namen
     * aus dem Konto (`isMember`), einer von jemandem ohne Konto den Namen aus
     * dem Repository, und einer ohne jede Angabe gar keinen. Der Name aus dem
     * Konto geht vor: er ist der, unter dem die Person hier auftritt, während
     * die Angabe im Commit von der Git-Einstellung eines Rechners stammt.
     *
     * @return array{name: string|null, email: string|null, isMember: bool}
     */
    private static function author(Commit $commit): array
    {
        // Über den Fremdschlüssel und nicht über die Beziehung: ob es ein Konto
        // gibt, steht am Commit selbst — und nur so ist „keines" ein Fall, mit
        // dem hier auch gerechnet wird, statt einer Möglichkeit, die sich erst
        // im Betrieb zeigt.
        $account = $commit->author_id === null ? null : $commit->author;

        return [
            'name' => $account === null ? $commit->author_name : $account->name,
            'email' => $commit->author_email,
            'isMember' => $account !== null,
        ];
    }
}
