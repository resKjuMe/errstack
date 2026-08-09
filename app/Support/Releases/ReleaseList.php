<?php

namespace App\Support\Releases;

use App\Enums\IssueStatus;
use App\Enums\ReleaseSort;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Release;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Releases\Health\ReleaseHealth;
use App\Support\Releases\Health\ReleaseHealthData;
use App\Support\Releases\Health\ReleaseHealthSummary;
use App\Support\Releases\Health\SessionWindow;
use App\Support\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Die Versionsliste: Abfrage und Darstellung einer Seite.
 *
 * Sie beantwortet die Frage, mit der nach einer Auslieferung als Erstes jemand
 * kommt: **ist etwas dazugekommen?** Nicht „wie viele Fehler gibt es" — das
 * steht in der Fehlerliste — sondern wie viele mit dieser Version **neu**
 * aufgetaucht sind und wie viele davon inzwischen erledigt sind.
 *
 * Beide Zahlen kommen aus **einer** Abfrage über die Verweise am Fehler-Eintrag
 * (`issues.first_release_id`), nicht aus einer je Zeile. Der Unterschied ist
 * eine Abfrage gegen fünfzig, und bei einer Liste, die im Sekundentakt
 * aufgeschlagen wird, ist das der Unterschied zwischen einer Seite und einer
 * Last.
 *
 * **Gesundheit und Verbreitung stehen daneben** (R7/R8): wie viele Sitzungen
 * mit dieser Version abstürzen und wie viele Menschen sie schon benutzen. Beide
 * Zahlen kommen für die ganze Seite in einem Rutsch
 * ({@see ReleaseHealth::summarizeMany()}) und nicht je Zeile — sonst wären
 * fünfzig Versionen hundertfünfzig Abfragen.
 */
final class ReleaseList
{
    /**
     * Einträge je Seite — wie in der Fehlerliste.
     */
    public const PER_PAGE = 50;

    /**
     * Eine Seite der Liste, fertig für die Oberfläche.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(GlobalFilter $filter, ?ReleaseSort $sort = null): LengthAwarePaginator
    {
        $window = SessionWindow::fromFilter($filter);

        $page = self::query($filter, $sort ?? ReleaseSort::default(), $window)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Die Zahlen **vor** dem Umwandeln, denn danach sind die Einträge
        // Felder und keine Modelle mehr — und die Kennungen der Seite sind
        // genau die, für die gezählt werden muss.
        /** @var Collection<int, Release> $releases */
        $releases = $page->getCollection();

        $ids = $releases->map(fn (Release $release): int => $release->id)->values()->all();

        $counts = self::issueCounts($ids);
        $health = (new ReleaseHealth)->summarizeMany($releases, $window);

        $page->through(fn (Release $release): array => self::present(
            $release,
            $counts[$release->id] ?? ['new' => 0, 'resolved' => 0],
            $health[$release->id] ?? null,
        ));

        return $page;
    }

    /**
     * Die Abfrage hinter der Liste.
     *
     * Der Zeitraum wirkt anders als bei den Ereignissen: eine Version hat eine
     * **Spanne** — erstes bis letztes Auftreten —, und gefragt ist, ob sie im
     * gewählten Zeitraum in Erscheinung getreten ist. Das ist dieselbe
     * Überlegung wie bei den Fehler-Einträgen (siehe
     * {@see GlobalFilter::overlapping()}).
     *
     * Der zweite Zweig ist der Fall, den die Überschneidung nicht erwischt: eine
     * Version, die über die Schnittstelle angekündigt wurde und aus der noch
     * keine einzige Meldung eingetroffen ist. Sie hat keine Spanne — und wäre
     * ausgerechnet am Tag der Auslieferung nicht in der Liste, also genau dann,
     * wenn jemand nachsieht. Für sie zählt der Zeitpunkt der Auslieferung,
     * ersatzweise der ihrer Ankündigung.
     *
     * @return Builder<Release>
     */
    public static function query(GlobalFilter $filter, ReleaseSort $sort, SessionWindow $window): Builder
    {
        $query = Release::query()
            // Ausdrücklich die Spalten der Versionen: die Sortierungen hängen
            // Unterabfragen an, und ohne die Einschränkung stünden deren
            // Summenspalten mit in der Zeile — bis eine davon heißt wie eine
            // hier und sie stillschweigend überschreibt.
            ->select('releases.*')
            ->with(['project:id,name,slug,organization_id', 'project.organization:id,slug'])
            // Wie viele Bauartefakte hochgeladen sind (R5). Die Zahl steht in der
            // Liste, weil „keine Quellkarten hochgeladen" sonst erst auf einer
            // Fehlerseite auffällt — also dann, wenn es zu spät ist, um noch etwas
            // daran zu haben.
            ->withCount('artifacts')
            // Durchgehend mit Tabellennamen: die Sortierungen hängen
            // Unterabfragen an, die ihrerseits `project_id` und `release_id`
            // führen. Ohne die Angabe wäre `where project_id = …` je nach
            // gewählter Sortierung mehrdeutig — und das ist ein Fehler, der
            // erst auftritt, wenn jemand die Sortierung umstellt.
            ->whereIn('releases.project_id', $filter->projectIds())
            ->where(function (Builder $query) use ($filter): void {
                $query
                    ->where(function (Builder $seen) use ($filter): void {
                        $seen
                            ->where('releases.last_event_at', '>=', $filter->fromUtc())
                            ->where('releases.first_event_at', '<=', $filter->toUtc());
                    })
                    ->orWhere(function (Builder $announced) use ($filter): void {
                        $announced
                            ->whereNull('releases.first_event_at')
                            ->whereBetween(
                                DB::raw('coalesce(releases.released_at, releases.created_at)'),
                                [$filter->fromUtc(), $filter->toUtc()],
                            );
                    });
            });

        $sort->apply($query, $window);

        return $query;
    }

    /**
     * Neue und davon erledigte Fehler je Version — in einer Abfrage.
     *
     * „Neu" heißt: der Fehler wurde in dieser Version zum ersten Mal gesehen.
     * „Erledigt" heißt: davon ist er inzwischen erledigt — **nicht** „in dieser
     * Version behoben". Die zweite Aussage bräuchte den Vermerk, in welcher
     * Auslieferung jemand einen Fehler geschlossen hat, und den gibt es erst
     * mit dem Bearbeiten von Fehlern (S6). Bis dahin wäre sie geraten, und eine
     * geratene Zahl in einer Auslieferungs-Übersicht ist schlimmer als keine:
     * sie sieht aus wie eine Messung.
     *
     * @param  list<int>  $releaseIds
     * @return array<int, array{new: int, resolved: int}>
     */
    private static function issueCounts(array $releaseIds): array
    {
        if ($releaseIds === []) {
            return [];
        }

        $rows = Issue::query()
            ->selectRaw('first_release_id as release_id')
            ->selectRaw('count(*) as new_count')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as resolved_count', [IssueStatus::Resolved->value])
            ->whereIn('first_release_id', $releaseIds)
            ->groupBy('first_release_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('release_id')] = [
                'new' => (int) $row->getAttribute('new_count'),
                'resolved' => (int) $row->getAttribute('resolved_count'),
            ];
        }

        return $counts;
    }

    /**
     * Eine Zeile.
     *
     * Zahlen kommen doppelt — einmal roh, einmal geschrieben — wie in der
     * Fehlerliste und aus demselben Grund: wie eine Zahl aussieht, entscheidet
     * die Sprache, und die kennt der Server.
     *
     * @param  array{new: int, resolved: int}  $counts
     * @return array<string, mixed>
     */
    private static function present(Release $release, array $counts, ?ReleaseHealthSummary $health): array
    {
        return [
            'id' => $release->id,
            'version' => $release->version,
            'ref' => $release->ref,
            'url' => $release->url,
            // Ob die Angabe eine Rangfolge hat. Die Oberfläche zeigt damit an,
            // dass eine Version außer der Reihe steht — sonst sähe es aus, als
            // wäre die Sortierung kaputt.
            'isOrdered' => $release->sort_major !== null,
            'releasedAt' => $release->released_at?->toIso8601String(),
            'releasedAtLabel' => Formats::dateTime($release->released_at),
            'firstEventAt' => $release->first_event_at?->toIso8601String(),
            'firstEventAtLabel' => Formats::dateTime($release->first_event_at),
            'lastEventAt' => $release->last_event_at?->toIso8601String(),
            'lastEventAtLabel' => Formats::dateTime($release->last_event_at),
            'newIssues' => $counts['new'],
            'newIssuesLabel' => Formats::number($counts['new']),
            'resolvedIssues' => $counts['resolved'],
            'resolvedIssuesLabel' => Formats::number($counts['resolved']),
            // `artifacts_count` steht am Modell, sobald die Abfrage es mitgezählt
            // hat; der Nullwert ist der Fall, in dem eine Version ohne diese Liste
            // dargestellt wird.
            'artifacts' => (int) ($release->artifacts_count ?? 0),
            // Gesundheit und Verbreitung (R7). Sie stehen in der Liste und
            // nicht erst auf der Detailseite, weil die Frage nach einer
            // Auslieferung „welche ist die schlechte?" lautet — und die
            // beantwortet nur ein Nebeneinander.
            'health' => $health === null ? null : ReleaseHealthData::summary($health),
            // Der Weg von der Version in die Fehlerliste: „was ist mit dieser
            // Auslieferung dazugekommen?" ist die Frage, die man von hier aus
            // als Nächstes stellt.
            'issuesHref' => route('issues.index', ['q' => SearchQuery::term('firstRelease', $release->version)]),
            // Die Detailseite: was in dieser Auslieferung steckt (R2).
            'href' => route('releases.show', $release),
            'project' => self::project($release),
        ];
    }

    /**
     * Das Projekt der Zeile — samt Link auf seine Seite.
     *
     * @return array{name: string, slug: string, href: string}|null
     */
    private static function project(Release $release): ?array
    {
        $project = $release->project;

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
