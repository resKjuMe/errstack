<?php

namespace App\Support\Performance\Trends;

use App\Enums\TrendDirection;
use App\Enums\TrendListSort;
use App\Models\Project;
use App\Models\TransactionTrendDetection;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Die Trend-Liste: Abfrage und Darstellung einer Seite.
 *
 * **Gefiltert wird über den Bruchpunkt und nicht über den Zeitpunkt der
 * Feststellung.** Die Frage der Seite ist „was hat sich in diesem Zeitraum
 * geändert", nicht „was hat der Durchlauf in diesem Zeitraum bemerkt". Der
 * Unterschied fällt genau dann auf, wenn er zählt: eine Verschlechterung von
 * vorgestern, die erst heute belegbar wurde, gehört unter vorgestern — dort
 * sucht sie, wer sie mit einer Auslieferung zusammenbringen will.
 *
 * **Verschlechterungen zuerst.** Die Liste zeigt beide Richtungen, aber nicht
 * gleichrangig: eine Verbesserung ist eine Bestätigung, eine Verschlechterung
 * ist Arbeit. Innerhalb der Richtung entscheidet der Umfang der Änderung — von
 * 200 ms auf 900 ms steht über von 200 ms auf 260 ms.
 */
final class TrendList
{
    public const PER_PAGE = 50;

    /**
     * Eine Seite der Liste, fertig für die Oberfläche.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(
        GlobalFilter $filter,
        TrendListSort $sort,
        ?TrendDirection $direction,
        ?bool $seen,
    ): LengthAwarePaginator {
        $page = self::query($filter, $sort, $direction, $seen)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->through(fn (TransactionTrendDetection $detection): array => self::present($detection, $filter));

        return $page;
    }

    /**
     * @return Builder<TransactionTrendDetection>
     */
    public static function query(
        GlobalFilter $filter,
        TrendListSort $sort,
        ?TrendDirection $direction,
        ?bool $seen,
    ): Builder {
        $query = TransactionTrendDetection::query()
            ->with([
                'project:id,name,slug,organization_id',
                'project.organization:id,slug',
                'deploy:id,release_id,name,finished_at',
                'deploy.release:id,version',
                'seenBy:id,name',
            ])
            ->whereIn('project_id', $filter->projectIds())
            ->where('breakpoint_at', '>=', $filter->fromUtc())
            ->where('breakpoint_at', '<', $filter->toUtc());

        // Die Umgebung steht an der Feststellung selbst — gerechnet wird
        // ohnehin nur die Standard-Umgebung eines Projekts ({@see TrendScan}).
        // Die Einschränkung greift deshalb selten, und wenn, dann richtig: wer
        // in der Filterleiste `staging` wählt, soll keine Produktionszahlen
        // sehen.
        if ($filter->environment !== null) {
            $query->where('environment', $filter->environment);
        }

        if ($direction !== null) {
            $query->where('direction', $direction);
        }

        if ($seen !== null) {
            $seen ? $query->whereNotNull('seen_at') : $query->whereNull('seen_at');
        }

        $sort->apply($query);

        return $query;
    }

    /**
     * Eine Zeile, wie die Oberfläche sie braucht.
     *
     * Die Zahlen kommen roh **und** geschrieben: die rohen vergleicht und
     * gestaltet die Oberfläche, die geschriebenen stehen da. Wie eine Dauer
     * aussieht, entscheidet ihre Größenordnung, und wie eine Zahl aussieht, die
     * Sprache — beides weiß der Server besser als eine Vorlage.
     *
     * @return array<string, mixed>
     */
    private static function present(TransactionTrendDetection $detection, GlobalFilter $filter): array
    {
        $deploy = $detection->deploy;

        return [
            'id' => $detection->id,
            'name' => $detection->name,
            'op' => $detection->operation(),
            'environment' => $detection->environment,
            'direction' => $detection->direction->value,
            'directionLabel' => $detection->direction->label(),
            'breakpointAt' => $detection->breakpoint_at->toIso8601String(),
            'breakpointAtLabel' => Formats::dateTime($detection->breakpoint_at),
            'beforeP95Us' => $detection->before_p95_us,
            'afterP95Us' => $detection->after_p95_us,
            'changeRatio' => $detection->change_ratio,
            'changeLabel' => Formats::number(abs($detection->change_ratio) * 100, 0).' %',
            'beforeCountLabel' => Formats::number($detection->before_count),
            'afterCountLabel' => Formats::number($detection->after_count),
            // Der z-Wert steht in der Zeile, weil er die Zusage belegt, unter der
            // sie überhaupt da ist. Auf eine Nachkommastelle: mehr wäre eine
            // Genauigkeit, die die Klassenbreite der Verteilung nicht hergibt.
            'confidenceLabel' => Formats::number($detection->z_score, 1),
            'deploy' => $deploy === null ? null : [
                'version' => $deploy->release->version,
                'atLabel' => Formats::dateTime($deploy->finished_at),
            ],
            'seenAtLabel' => Formats::dateTime($detection->seen_at),
            'seenBy' => $detection->seenBy?->name,
            'isSeen' => $detection->seen_at !== null,
            'project' => self::project($detection),
            // Der Weg in die Detailanalyse (PF3) mit demselben Zeitraum: dort
            // steht der Verlauf, an dem sich der Bruch nachsehen lässt.
            'href' => route('performance.transaction', $filter->formValues() + [
                'name' => $detection->name,
                'op' => $detection->op,
            ]),
            'seenUrl' => route('performance.trends.seen', $detection),
        ];
    }

    /**
     * @return array{name: string, slug: string, href: string}|null
     */
    private static function project(TransactionTrendDetection $detection): ?array
    {
        $project = $detection->project;

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
