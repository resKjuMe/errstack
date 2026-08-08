<?php

namespace App\Support\Performance\Detection;

use App\Enums\IssueCategory;
use App\Enums\IssueStatus;
use App\Enums\PerformanceIssueSort;
use App\Enums\PerformanceProblem;
use App\Models\Issue;
use App\Models\PerformanceDetection;
use App\Models\Project;
use App\Models\TransactionSpan;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Issues\IssueList;
use App\Support\Issues\IssueSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Die Liste der Leistungsprobleme: Abfrage und Darstellung einer Seite.
 *
 * Das Gegenstück zu {@see IssueList} und mit derselben
 * Zusage: gelesen werden ausschließlich die Zähler am Eintrag, nie die
 * einzelnen Funde. Ein `sum(time_lost_us)` über die Funde wäre bei der ersten
 * Vorführung schneller und im Betrieb die Stelle, an der die Seite nicht mehr
 * aufgeht.
 *
 * **Warum keine gemeinsame Klasse mit der Fehlerliste:** die beiden zeigen
 * verschiedene Spalten (verlorene Zeit statt Grad), sortieren nach
 * verschiedenen Vorgaben und filtern nach verschiedenen Merkmalen (Muster statt
 * Merkmal). Was sie teilen, ist die Tabelle — und die teilen sie über das
 * Modell, nicht über eine Klasse mit zwei Betriebsarten.
 */
final class PerformanceIssueList
{
    public const PER_PAGE = 50;

    /**
     * Eine Seite der Liste, fertig für die Oberfläche.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(
        GlobalFilter $filter,
        PerformanceIssueSort $sort,
        ?IssueStatus $status,
        ?PerformanceProblem $problem,
    ): LengthAwarePaginator {
        $page = self::query($filter, $sort, $status, $problem)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $ids = $page->getCollection()->map(fn (Issue $issue): int => $issue->id)->values()->all();

        $series = IssueSeries::forIssues($ids, $filter);

        $page->through(fn (Issue $issue): array => self::present($issue, $series[$issue->id] ?? []));

        return $page;
    }

    /**
     * @return Builder<Issue>
     */
    public static function query(
        GlobalFilter $filter,
        PerformanceIssueSort $sort,
        ?IssueStatus $status,
        ?PerformanceProblem $problem,
    ): Builder {
        $query = Issue::query()->with(['project:id,name,slug,organization_id', 'project.organization:id,slug']);

        $query->ofCategory(IssueCategory::Performance);

        $filter->overlapping($query);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($problem !== null) {
            // Das Muster steht in `type` — bei einem Fehler die Klasse der
            // Ausnahme, hier das Muster. Dieselbe Spalte, dieselbe Frage: „was
            // für eine Sorte Problem ist das".
            $query->where('type', $problem->value);
        }

        $sort->apply($query);

        return $query;
    }

    /**
     * Die Zahlen eines Eintrags — auch die, die eine Fehlerzeile nicht hat.
     *
     * Die verlorene Zeit kommt roh **und** geschrieben: die rohe Zahl sortiert
     * und vergleicht die Oberfläche, die geschriebene steht da. Wie eine Dauer
     * dasteht, entscheidet ihre Größenordnung — „1500000 µs" liest niemand als
     * anderthalb Sekunden.
     *
     * @param  list<int>  $series
     * @return array<string, mixed>
     */
    private static function present(Issue $issue, array $series): array
    {
        $problem = PerformanceProblem::tryFrom((string) $issue->type);

        return [
            'id' => $issue->id,
            'title' => $issue->title ?? __('performance_issues.list.untitled'),
            'culprit' => $issue->culprit,
            'problem' => $problem?->value,
            'problemLabel' => $problem?->label(),
            'status' => $issue->status->value,
            'statusLabel' => $issue->status->label(),
            'priority' => $issue->priority->value,
            'priorityLabel' => $issue->priority->label(),
            'timesSeen' => $issue->times_seen,
            'timesSeenLabel' => Formats::number($issue->times_seen),
            'usersSeen' => $issue->users_seen,
            'usersSeenLabel' => Formats::number($issue->users_seen),
            'timeLostUs' => $issue->time_lost_us,
            // Der Mittelwert je Vorfall: er sagt, ob die Summe aus vielen
            // kleinen Verlusten stammt oder aus wenigen großen — und das
            // entscheidet, ob sich die Behebung lohnt.
            'timeLostPerEventUs' => $issue->times_seen > 0
                ? (int) round($issue->time_lost_us / $issue->times_seen)
                : null,
            'firstSeen' => $issue->first_seen->toIso8601String(),
            'firstSeenLabel' => Formats::dateTime($issue->first_seen),
            'lastSeen' => $issue->last_seen->toIso8601String(),
            'lastSeenLabel' => Formats::dateTime($issue->last_seen),
            'project' => self::project($issue),
            'href' => route('performance.issues.show', $issue),
            'series' => $series,
        ];
    }

    /**
     * Die Beispiele eines Eintrags: die teuersten Funde mit ihren Schritten.
     *
     * Sie sind die Antwort auf die einzige Frage, die nach dem Lesen der
     * Überschrift bleibt — „wo sehe ich das?". Deshalb kommen sie samt der
     * betroffenen Schritte und nicht als Verweis: wer eine Kennung angezeigt
     * bekommt, mit der er anderswo nachschlagen soll, sieht nicht nach.
     *
     * @return list<array<string, mixed>>
     */
    public static function examples(Issue $issue): array
    {
        $detections = $issue->detections()
            ->with('transaction:id,name,trace_id,started_at,duration_us,environment')
            ->orderByDesc('time_lost_us')
            ->limit(PerformanceDetection::EXAMPLE_LIMIT)
            ->get();

        return $detections->map(static function (PerformanceDetection $detection): array {
            $transaction = $detection->transaction;

            return [
                'id' => $detection->id,
                'traceId' => $detection->trace_id,
                'description' => $detection->description,
                'spanCount' => $detection->span_count,
                'timeLostUs' => $detection->time_lost_us,
                'occurredAt' => $detection->occurred_at->toIso8601String(),
                'occurredAtLabel' => Formats::dateTime($detection->occurred_at),
                'evidence' => $detection->evidence ?? [],
                'transaction' => $transaction === null ? null : [
                    'name' => $transaction->name,
                    'durationUs' => $transaction->duration_us,
                    'environment' => $transaction->environment,
                ],
                'spans' => $detection->affectedSpans()->map(static fn (TransactionSpan $span): array => [
                    'spanId' => $span->span_id,
                    'op' => $span->op,
                    'description' => $span->description,
                    'durationUs' => (int) $span->duration_us,
                    'status' => $span->status,
                ])->values()->all(),
            ];
        })->values()->all();
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
