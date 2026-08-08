<?php

namespace App\Http\Controllers;

use App\Enums\IssueCategory;
use App\Enums\IssueStatus;
use App\Enums\PerformanceIssueSort;
use App\Enums\PerformanceProblem;
use App\Http\Requests\GlobalFilterRequest;
use App\Http\Requests\PerformanceIssueListRequest;
use App\Models\Issue;
use App\Support\FilterData;
use App\Support\Formats;
use App\Support\Issues\IssueSeries;
use App\Support\Performance\Detection\PerformanceIssueList;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Leistungsprobleme: eine eigene Ansicht neben der Fehlerliste.
 *
 * **Eine eigene Seite und nicht ein Filter in der Fehlerliste.** Der Auftrag
 * verlangt die Trennung, und sie ist mehr als eine Vorsichtsmaßnahme: die
 * beiden Listen beantworten verschiedene Fragen und zeigen deshalb verschiedene
 * Spalten. Bei einem Fehler zählt, wann er zuletzt war; bei einem
 * Leistungsproblem, was es kostet. Ein gemeinsamer Bildschirm mit einem Schalter
 * darüber hätte in jeder Zeile Spalten, die für die Hälfte der Einträge leer
 * sind.
 */
class PerformanceIssueController extends Controller
{
    public function index(PerformanceIssueListRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $period = IssueSeries::periodFor($filter);

        $issues = PerformanceIssueList::paginate(
            $filter,
            $request->sort(),
            $request->status(),
            $request->problem(),
        );

        return Inertia::render('performance/Issues', [
            'filter' => FilterData::bar($filter),
            'issues' => $issues,
            'list' => $request->listValues(),
            'totalLabel' => Formats::number($issues->total()),
            'sortOptions' => PerformanceIssueSort::options(),
            'statusOptions' => self::statusOptions(),
            'problemOptions' => self::problemOptions(),
            'series' => [
                'period' => $period->value,
                'periodLabel' => $period->label(),
            ],
            // Dieselbe Anmerkung wie in der Fehlerliste: die Zähler eines
            // Eintrags sind über alle Umgebungen hinweg gebildet, eine
            // Einschränkung darauf wäre also gelogen.
            'environmentIgnored' => $filter->environment !== null,
        ]);
    }

    /**
     * Ein einzelnes Leistungsproblem mit seinen Beispielen.
     */
    public function show(GlobalFilterRequest $request, Issue $issue): InertiaResponse
    {
        Gate::authorize('view', $issue->project);

        // Ein Fehler ist unter dieser Adresse nicht zu haben. Die Trennung gilt
        // auch für den einzelnen Eintrag: wer eine Fehler-Kennung hier einsetzt,
        // bekommt keine halb passende Ansicht, sondern nichts.
        abort_unless($issue->category === IssueCategory::Performance, 404);

        $filter = $request->filter();
        $problem = PerformanceProblem::tryFrom((string) $issue->type);

        return Inertia::render('performance/IssueDetail', [
            'filter' => FilterData::bar($filter),
            'issue' => [
                'id' => $issue->id,
                'title' => $issue->title ?? __('performance_issues.list.untitled'),
                'culprit' => $issue->culprit,
                'problem' => $problem?->value,
                'problemLabel' => $problem?->label(),
                'problemDescription' => $problem?->description(),
                'status' => $issue->status->value,
                'statusLabel' => $issue->status->label(),
                'priority' => $issue->priority->value,
                'priorityLabel' => $issue->priority->label(),
                'timesSeen' => $issue->times_seen,
                'timesSeenLabel' => Formats::number($issue->times_seen),
                'usersSeen' => $issue->users_seen,
                'usersSeenLabel' => Formats::number($issue->users_seen),
                'timeLostUs' => $issue->time_lost_us,
                'timeLostPerEventUs' => $issue->times_seen > 0
                    ? (int) round($issue->time_lost_us / $issue->times_seen)
                    : null,
                'firstSeenLabel' => Formats::dateTime($issue->first_seen),
                'lastSeenLabel' => Formats::dateTime($issue->last_seen),
                'project' => $issue->project === null ? null : [
                    'name' => $issue->project->name,
                    'href' => route('projects.show', [$issue->project->organization, $issue->project]),
                ],
            ],
            'examples' => PerformanceIssueList::examples($issue),
            'indexHref' => route('performance.issues.index', $filter->formValues()),
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function statusOptions(): array
    {
        return [
            ['value' => PerformanceIssueListRequest::STATUS_ANY, 'label' => __('issues.filter.any_status')],
            ...IssueStatus::options(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function problemOptions(): array
    {
        return [
            ['value' => PerformanceIssueListRequest::PROBLEM_ANY, 'label' => __('performance_issues.filter.any_problem')],
            ...PerformanceProblem::options(),
        ];
    }
}
