<?php

namespace App\Support\Dashboards;

use App\Models\DashboardWidget;
use App\Models\Project;
use App\Support\Discover\DiscoverData;
use App\Support\Discover\DiscoverEngine;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverLimits;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\DiscoverRow;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Search\SearchQuery;

/**
 * Was eine Kachel zeigt — die eine Stelle, an der aus einer gespeicherten
 * Abfrage Zahlen werden.
 *
 * **Je Kachel genau eine Abfrage.** Die Darstellungsart entscheidet, welche:
 * ein Verlauf braucht die Zeitreihe, alles andere die Rangliste. Beides zu
 * holen und die Hälfte wegzuwerfen wäre auf einem Bildschirm mit zwanzig
 * Kacheln die doppelte Arbeit — und die Zusage „lädt ohne merkliche
 * Verzögerung" hinge dann an der halben Geschwindigkeit.
 *
 * **Nebeneinander gefragt wird nicht hier, sondern im Browser.** Jede Kachel
 * holt ihre Zahlen über eine eigene Adresse; zwanzig davon laufen als zwanzig
 * Anfragen gleichzeitig und nicht als eine, die zwanzigmal hintereinander
 * rechnet. Das ist der Grund, warum diese Klasse **eine** Kachel beantwortet
 * und keine Liste: eine Schleife hier wäre genau die Reihe nacheinander, die
 * vermieden werden soll. Der Nebeneffekt ist, dass das Raster sofort dasteht
 * und sich füllt, statt auf die langsamste Kachel zu warten.
 *
 * **Eine abgelehnte Abfrage ist eine Auskunft und kein Loch.** Der Motor sagt
 * mit Grenze und verlangtem Wert, warum er nicht gerechnet hat; die Kachel
 * zeigt das an ihrer Stelle, und die übrigen neunzehn stehen unverändert da.
 */
final class WidgetData
{
    public function __construct(
        private readonly DiscoverEngine $engine = new DiscoverEngine,
    ) {}

    /**
     * Die Kachel mit Zahlen.
     *
     * @return array<string, mixed>
     */
    public function resolve(DashboardWidget $widget, GlobalFilter $filter, DiscoverLimits $limits): array
    {
        $overrides = $widget->widgetOverrides();
        $project = $overrides->projectFor($filter);
        $scope = self::scope($widget, $filter, $project);

        if ($project === null) {
            // Ohne genau ein Projekt rechnet der Motor nicht, und die Kachel rät
            // nicht, welches gemeint war — dieselbe Regel wie in der freien
            // Auswertung. Der Unterschied ist nur, dass hier eine Kachel und
            // nicht die Seite betroffen ist.
            $reason = $overrides->projectMissing($filter) ? 'project_missing' : 'project_required';

            return self::payload($widget, $scope, error: [
                'reason' => $reason,
                'message' => __('dashboards.widget.error.'.$reason, ['project' => $overrides->projectSlug ?? '']),
                'context' => ['project' => $overrides->projectSlug ?? ''],
            ]);
        }

        $stored = $widget->widgetQuery();
        $range = $overrides->rangeFor($filter);
        $environment = $overrides->environmentFor($filter);

        try {
            $query = $stored->toDiscoverQuery(
                projectId: $project->id,
                range: $range,
                timezone: $filter->timezone,
                limits: $limits,
                // Die Umgebung wird zur Suchbedingung und nicht zu einem zweiten
                // Weg, eine Auswertung einzuschränken — dieselbe Entscheidung
                // wie in {@see App\Http\Requests\DiscoverRequest}.
                search: $environment === null ? '' : SearchQuery::term('environment', $environment),
                // Eine große Zahl liest eine Zeile. Fünfzig zu lesen und
                // neunundvierzig wegzuwerfen wäre Arbeit für nichts.
                limitOverride: $widget->type->isSingleValue() ? 1 : 0,
            );

            return $widget->type->isSeries()
                ? $this->seriesPayload($widget, $query, $stored, $scope)
                : $this->tablePayload($widget, $query, $scope);
        } catch (DiscoverException $exception) {
            return self::payload($widget, $scope, error: $exception->toArray());
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function tablePayload(DashboardWidget $widget, DiscoverQuery $query, array $scope): array
    {
        $result = $this->engine->table($query);

        return self::payload($widget, $scope, columns: DiscoverData::columns($query), table: [
            'rows' => array_map(
                static fn (DiscoverRow $row): array => [
                    'groups' => $row->groups,
                    'values' => $row->values,
                ],
                $result->rows,
            ),
            'truncated' => $result->truncated,
            'cached' => $result->cached,
            'unavailable' => $result->unavailable,
            'searchError' => $result->searchError,
        ]);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function seriesPayload(DashboardWidget $widget, DiscoverQuery $query, WidgetQuery $stored, array $scope): array
    {
        $series = $this->engine->series($query->every($stored->intervalFor($query->range)));

        return self::payload(
            $widget,
            $scope,
            columns: DiscoverData::columns($query),
            series: DiscoverData::series($series, __('discover.chart.all')),
        );
    }

    /**
     * Der Rahmen, in dem diese Kachel gerechnet hat — damit an ihr steht, wenn
     * sie etwas anderes zeigt als der Rest des Bildschirms.
     *
     * Ohne diesen Vermerk wäre eine Kachel mit eigenem Zeitraum die gefährlichste
     * Zahl auf dem Dashboard: sie steht neben Kacheln, die etwas anderes meinen,
     * und sieht genauso aus.
     *
     * @return array<string, mixed>
     */
    private static function scope(DashboardWidget $widget, GlobalFilter $filter, ?Project $project): array
    {
        $overrides = $widget->widgetOverrides();
        $range = $overrides->rangeFor($filter);

        return [
            'project' => $project === null ? null : ['slug' => $project->slug, 'name' => $project->name],
            'environment' => $overrides->environmentFor($filter),
            'rangeLabel' => Formats::dateTime($range->from).' – '.Formats::dateTime($range->to),
            'overridden' => ! $overrides->isEmpty(),
            'overrides' => $overrides->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<array{key: string, label: string, kind: string, unit: string, format: string}>  $columns
     * @param  array<string, mixed>|null  $table
     * @param  array<string, mixed>|null  $series
     * @param  array<string, mixed>|null  $error
     * @return array<string, mixed>
     */
    private static function payload(
        DashboardWidget $widget,
        array $scope,
        array $columns = [],
        ?array $table = null,
        ?array $series = null,
        ?array $error = null,
    ): array {
        return [
            'id' => $widget->id,
            'type' => $widget->type->value,
            'columns' => $columns,
            'table' => $table,
            'series' => $series,
            'error' => $error,
            'scope' => $scope,
        ];
    }
}
