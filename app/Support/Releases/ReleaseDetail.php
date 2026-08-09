<?php

namespace App\Support\Releases;

use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\Deploy;
use App\Models\Issue;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Releases\Health\ReleaseAdoptionSeries;
use App\Support\Releases\Health\ReleaseHealth;
use App\Support\Releases\Health\ReleaseHealthData;
use App\Support\Releases\Health\SessionWindow;
use App\Support\Search\SearchQuery;

/**
 * Die Detailseite einer Auslieferung: was drinsteckt.
 *
 * Sie beantwortet die Frage, die unmittelbar auf „es wurde ausgeliefert" folgt
 * — **was** wurde ausgeliefert, und von wem. Die Liste (R1) sagt, dass es die
 * Version gibt und wie viele Fehler mit ihr dazugekommen sind; hier stehen die
 * Änderungen selbst.
 *
 * **Und wie sie ausgegangen ist** (R8): Gesundheit, Verbreitung und der
 * Vergleich zur Vorversion. Der Vergleich ist dabei nicht das Beiwerk, sondern
 * der Zweck — „99,2 % absturzfrei" allein sagt niemandem, ob die Auslieferung
 * gut war, und erst „vorher waren es 99,8 %" macht daraus eine Aussage.
 *
 * Alle Zahlen hängen an der globalen Filterleiste (F7): Zeitraum und Umgebung
 * kommen von dort. Das Projekt nicht — es steht durch die Version fest.
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
     * Höchstens so viele Bauartefakte stehen auf der Seite.
     *
     * Ein Bundle je Einstiegspunkt und eine Quellkarte dazu — der Regelfall sind
     * ein paar Dutzend. Ein Bauvorgang, der jede Datei einzeln hochlädt, bringt
     * Tausende, und die Seite wäre eine Dateiliste mit einer Auslieferung
     * darüber. Wie bei den Commits wird sichtbar gekürzt.
     */
    public const MAX_ARTIFACTS = 50;

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
    public static function present(Release $release, GlobalFilter $filter): array
    {
        $release->loadMissing('project.organization');

        // Der Ausschnitt für alle Kennzahlen dieser Seite: Zeitraum und Umgebung
        // aus der Filterleiste, das Projekt aus der Version. Ein Ausschnitt für
        // alles, damit die Übersichtszahl, der Vergleich und die Kurve
        // dieselbe Frage beantworten — drei Zahlen aus drei Zeiträumen sähen
        // nebeneinander aus wie ein Widerspruch.
        $window = SessionWindow::fromFilter($filter, [$release->project_id]);

        $health = new ReleaseHealth;
        $comparison = $health->compare($release, $window->from, $window->to, $window->environment);

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

        $artifactCount = $release->artifacts()->count();

        $artifacts = $release->artifacts()->orderBy('name')->limit(self::MAX_ARTIFACTS)->get();

        return [
            // Gesundheit und Verbreitung (R7) samt Vergleich zur Vorversion —
            // die Zahlen, wegen derer jemand nach einer Auslieferung überhaupt
            // hierherkommt. Deshalb stehen sie im Rückgabewert vorn und auf der
            // Seite oben.
            'health' => ReleaseHealthData::summary($comparison['current']),
            'comparison' => ReleaseHealthData::comparison($comparison['current'], $comparison['previous']),
            'previousHref' => $comparison['previous'] === null
                ? null
                : route('releases.show', $comparison['previous']->release),
            'adoption' => ReleaseAdoptionSeries::present($release, $window),
            'issues' => self::issues($release),
            'artifacts' => $artifacts->map(fn (ReleaseArtifact $artifact): array => self::artifact($artifact))->all(),
            'artifactsLabel' => Formats::number($artifactCount),
            'artifactsTruncated' => $artifactCount > $artifacts->count(),
            'artifactsShownLabel' => Formats::number($artifacts->count()),
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
     * Was diese Auslieferung an Fehlern gebracht, erledigt und zurückgeholt hat.
     *
     * Drei Zahlen, die drei verschiedene Dinge über dieselbe Auslieferung sagen
     * — und jede führt in die Fehlerliste, gefiltert auf genau diese Menge. Eine
     * Zahl auf einer Übersichtsseite, hinter der man nicht nachsehen kann, ist
     * eine Behauptung.
     *
     * **Drei Abfragen und nicht eine mit `or`.** Jede der drei hängt an einer
     * anderen Spalte; zusammengefasst wäre es eine Bedingung, die keinen der
     * drei Indizes mehr benutzen kann — und drei gezählte Zeilen sind billig.
     *
     * Der Zeitraum der Filterleiste wirkt hier **nicht**: „mit dieser Version
     * kam dieser Fehler" ist eine Aussage über die Auslieferung und nicht über
     * die letzten 24 Stunden. Eingeschränkt sähe die Seite je nach Zeitraum so
     * aus, als hätte die Version verschieden viele Fehler gebracht.
     *
     * @return array<string, mixed>
     */
    private static function issues(Release $release): array
    {
        $id = $release->getKey();

        $new = Issue::query()->where('first_release_id', $id)->count();
        $resolved = Issue::query()->where('resolved_in_release_id', $id)->count();
        $regressed = Issue::query()->where('regressed_in_release_id', $id)->count();

        return [
            'new' => self::issueGroup($new, SearchQuery::term('firstRelease', $release->version)),
            'resolved' => self::issueGroup($resolved, SearchQuery::term('resolvedInRelease', $release->version)),
            'regressed' => self::issueGroup($regressed, SearchQuery::term('regressedInRelease', $release->version)),
        ];
    }

    /**
     * @return array{count: int, label: string, href: string}
     */
    private static function issueGroup(int $count, string $query): array
    {
        return [
            'count' => $count,
            'label' => Formats::number($count),
            'href' => route('issues.index', ['q' => $query]),
        ];
    }

    /**
     * Ein hochgeladenes Bauartefakt (R5).
     *
     * Die Debug-Kennung steht dabei nur als „ja/nein" da und nicht als
     * Zeichenkette: sie ist sechsunddreißig Zeichen lang und interessiert an
     * dieser Stelle nur in einer Hinsicht — ob die Zuordnung ohne den Pfad
     * auskommt.
     *
     * @return array<string, mixed>
     */
    private static function artifact(ReleaseArtifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'name' => $artifact->name,
            'kind' => $artifact->kind->value,
            'kindLabel' => $artifact->kind->label(),
            'sizeLabel' => Formats::bytes($artifact->size),
            'hasDebugId' => $artifact->debug_id !== null,
            'sourceMap' => $artifact->sourceMapName(),
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
