<?php

namespace App\Support;

use App\Enums\FilterPeriod;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Support\Filters\GlobalFilter;

/**
 * Nutzlast der globalen Filterleiste. Sie liegt als geteilte Eigenschaft `filter`
 * an der Antwort und nicht an der einzelnen Seite ({@see HandleInertiaRequests}):
 * die Leiste ist ein Baustein des Rahmens
 * (resources/js/shell/components/FilterBar.jsx) und wird dort genau einmal
 * gezeichnet.
 */
final class FilterData
{
    /**
     * @return array<string, mixed>
     */
    public static function bar(GlobalFilter $filter): array
    {
        return [
            'value' => $filter->formValues(),
            'projectOptions' => $filter->availableProjects
                ->map(fn (Project $project): array => [
                    'value' => $project->slug,
                    'label' => $project->name,
                ])->values()->all(),
            'environmentOptions' => array_map(
                fn (string $name): array => ['value' => $name, 'label' => $name],
                $filter->availableEnvironments,
            ),
            'periodOptions' => FilterPeriod::options(),
            // Die Leiste erkennt daran, ob überhaupt eingeschränkt ist — nur dann
            // hat „Zurücksetzen" etwas zu tun.
            'defaultPeriod' => FilterPeriod::default()->value,
            // Der aufgelöste Zeitraum als Text: „letzte 24 Stunden" allein sagt
            // nicht, welche 24 Stunden gemeint sind.
            'range' => [
                'label' => $filter->rangeLabel(),
                'from' => $filter->from->toIso8601String(),
                'to' => $filter->to->toIso8601String(),
            ],
            'timezone' => $filter->timezone,
            'labels' => [
                'projects' => __('filters.projects'),
                'allProjects' => __('filters.all_projects'),
                'environment' => __('filters.environment'),
                'allEnvironments' => __('filters.all_environments'),
                'period' => __('filters.period'),
                'from' => __('filters.from'),
                'to' => __('filters.to'),
                'reset' => __('filters.reset'),
                'noProjects' => __('filters.no_projects'),
            ],
        ];
    }
}
