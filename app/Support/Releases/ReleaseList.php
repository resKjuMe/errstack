<?php

namespace App\Support\Releases;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Release;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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
 * **Nicht hier: Gesundheit und Verbreitung.** Wie viele Sitzungen mit dieser
 * Version abstürzen und wie schnell sie sich ausbreitet, sind Fragen an die
 * Sitzungsdaten und damit R7. Diese Liste kommt mit dem aus, was aus den
 * Fehlern selbst hervorgeht.
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
    public static function paginate(GlobalFilter $filter): LengthAwarePaginator
    {
        $page = self::query($filter)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Die Zahlen **vor** dem Umwandeln, denn danach sind die Einträge
        // Felder und keine Modelle mehr — und die Kennungen der Seite sind
        // genau die, für die gezählt werden muss.
        $ids = $page->getCollection()->map(fn (Release $release): int => $release->id)->values()->all();

        $counts = self::issueCounts($ids);

        $page->through(fn (Release $release): array => self::present(
            $release,
            $counts[$release->id] ?? ['new' => 0, 'resolved' => 0],
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
    public static function query(GlobalFilter $filter): Builder
    {
        $query = Release::query()
            ->with(['project:id,name,slug,organization_id', 'project.organization:id,slug'])
            // Wie viele Bauartefakte hochgeladen sind (R5). Die Zahl steht in der
            // Liste, weil „keine Quellkarten hochgeladen" sonst erst auf einer
            // Fehlerseite auffällt — also dann, wenn es zu spät ist, um noch etwas
            // daran zu haben.
            ->withCount('artifacts')
            ->whereIn('project_id', $filter->projectIds())
            ->where(function (Builder $query) use ($filter): void {
                $query
                    ->where(function (Builder $seen) use ($filter): void {
                        $seen
                            ->where('last_event_at', '>=', $filter->fromUtc())
                            ->where('first_event_at', '<=', $filter->toUtc());
                    })
                    ->orWhere(function (Builder $announced) use ($filter): void {
                        $announced
                            ->whereNull('first_event_at')
                            ->whereBetween(
                                DB::raw('coalesce(released_at, created_at)'),
                                [$filter->fromUtc(), $filter->toUtc()],
                            );
                    });
            });

        $query->newestFirst();

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
    private static function present(Release $release, array $counts): array
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
            // Der Weg von der Version in die Fehlerliste: „was ist mit dieser
            // Auslieferung dazugekommen?" ist die Frage, die man von hier aus
            // als Nächstes stellt.
            'issuesHref' => route('issues.index', ['q' => SearchQuery::term('firstRelease', $release->version)]),
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
