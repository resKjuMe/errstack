<?php

namespace App\Support\Reports;

use App\Enums\CountPeriod;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Models\Project;
use App\Support\Issues\IssueSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Der Wochenbericht eines Projekts: was in einer Woche neu war, was erledigt
 * wurde, wohin es sich bewegt und wo es am meisten wehtut.
 *
 * **Er wird gerechnet und nicht abgelesen.** Die Zahlen stammen aus den
 * Tageszählern ({@see IssueCount}) und nicht aus den Ereignissen selbst: die
 * Aufbewahrung der Meldungen ist kurz, die der Zähler lang — ein Bericht über
 * die vergangene Woche, der die Meldungen zählen müsste, wäre je nach
 * Projekt-Einstellung schon beim Versand unvollständig.
 *
 * **Der Trend braucht die Vorwoche und keine Prognose.** „Doppelt so viele
 * Fehler wie letzte Woche" ist die Auskunft, die jemanden dazu bringt,
 * nachzusehen; eine absolute Zahl allein tut das nicht, weil niemand weiß, ob
 * sie viel ist.
 *
 * Zusammengeführte Einträge zählen beim Kopf mit ({@see IssueSeries::owners()}).
 * Dieselbe Zuordnung wie in der Verlaufsgrafik und aus demselben Grund: ein
 * beigetretener Eintrag ist nicht verschwunden, er steht nur nicht mehr für
 * sich — und ein Bericht, der ihn unterschlägt, zählt eine Fehlerwelle klein.
 */
final readonly class WeeklyProjectReport
{
    /**
     * @param  list<array{title: string, url: string, count: int}>  $topIssues
     * @param  list<array{name: string, count: int}>  $topAreas
     */
    private function __construct(
        public Project $project,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $newIssues,
        public int $resolvedIssues,
        public int $events,
        public int $previousEvents,
        public array $topIssues,
        public array $topAreas,
    ) {}

    /**
     * Der Bericht über die Woche, die mit `$start` beginnt.
     */
    public static function build(Project $project, CarbonImmutable $start, int $topCount = 5): self
    {
        $start = $start->startOfDay();
        $end = $start->addWeek();
        $previousStart = $start->subWeek();

        $issueIds = Issue::query()->where('project_id', $project->id)->pluck('id')->all();
        $counts = self::countsPerIssue($issueIds, $start, $end);

        return new self(
            project: $project,
            start: $start,
            end: $end,
            newIssues: Issue::query()
                ->where('project_id', $project->id)
                ->whereBetween('first_seen', [$start, $end])
                ->count(),
            resolvedIssues: Issue::query()
                ->where('project_id', $project->id)
                ->whereNotNull('resolved_at')
                ->whereBetween('resolved_at', [$start, $end])
                ->count(),
            events: array_sum($counts),
            previousEvents: array_sum(self::countsPerIssue($issueIds, $previousStart, $start)),
            topIssues: self::topIssues($project, $counts, $topCount),
            topAreas: self::topAreas($counts, $topCount),
        );
    }

    /**
     * Die Veränderung gegenüber der Vorwoche in Prozent — `null`, wenn es keine
     * Vorwoche zum Vergleichen gibt.
     *
     * Null ist hier keine Bequemlichkeit, sondern die einzig richtige Antwort:
     * von null auf hundert ist keine Steigerung um hundert Prozent und auch
     * keine um unendlich viele, sondern ein Anfang. Wer das als Zahl schreibt,
     * schreibt eine, die niemand deuten kann.
     */
    public function trendPercent(): ?float
    {
        if ($this->previousEvents === 0) {
            return null;
        }

        return round((($this->events - $this->previousEvents) / $this->previousEvents) * 100, 1);
    }

    /**
     * Gab es überhaupt etwas zu berichten?
     *
     * Auch die Vorwoche zählt mit: eine Woche ohne einen einzigen Fehler nach
     * einer lauten ist die beste Nachricht, die dieser Bericht überbringen
     * kann. Erst wenn in beiden Wochen nichts war, ist er eine Mail über nichts
     * — und die wäre der schnellste Weg, den Bericht dauerhaft abzubestellen.
     */
    public function hasActivity(): bool
    {
        return $this->events > 0
            || $this->previousEvents > 0
            || $this->newIssues > 0
            || $this->resolvedIssues > 0;
    }

    /**
     * Ereignisse je Fehler-Eintrag im Zeitraum, bereits auf den Kopf gebucht.
     *
     * @param  list<int>  $issueIds
     * @return array<int, int>
     */
    private static function countsPerIssue(array $issueIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($issueIds === []) {
            return [];
        }

        $owners = IssueSeries::owners($issueIds);
        $totals = [];

        // Über den Query Builder und nicht über das Modell: die Zeilen sind
        // Summen und keine Zähler — `sum(event_count)` hat am Modell keine
        // Entsprechung.
        $rows = DB::table((new IssueCount)->getTable())
            ->select('issue_id', DB::raw('sum(event_count) as total'))
            ->whereIn('issue_id', $issueIds)
            ->where('period', CountPeriod::Day->value)
            ->where('window_start', '>=', $start)
            ->where('window_start', '<', $end)
            ->groupBy('issue_id')
            ->get();

        foreach ($rows as $row) {
            $issueId = (int) $row->issue_id;
            $owner = $owners[$issueId] ?? $issueId;

            $totals[$owner] = ($totals[$owner] ?? 0) + (int) $row->total;
        }

        return $totals;
    }

    /**
     * Das Projekt steht dabei, weil der Link es braucht: die Adresse eines
     * Fehlers trägt die Organisation, und dieser Bericht entsteht in einem
     * geplanten Lauf — dort gibt es keine Anfrage, aus der sie sich ergäbe.
     *
     * @param  array<int, int>  $counts
     * @return list<array{title: string, url: string, count: int}>
     */
    private static function topIssues(Project $project, array $counts, int $limit): array
    {
        $top = self::highest($counts, $limit);

        if ($top === []) {
            return [];
        }

        $issues = Issue::query()->whereIn('id', array_keys($top))->get()->keyBy('id');
        $result = [];

        foreach ($top as $issueId => $count) {
            $issue = $issues->get($issueId);

            if (! $issue instanceof Issue) {
                continue;
            }

            $result[] = [
                'title' => $issue->title ?? __('reports.weekly.untitled'),
                'url' => route('issues.show', ['organization' => $project->organization, 'issue' => $issue]),
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * Die meistbetroffenen Bereiche — gemeint ist die Stelle im Quelltext, an
     * der die Fehler auflaufen (`culprit`).
     *
     * Sie beantwortet die Frage, die die Liste der einzelnen Fehler offen
     * lässt: fünf verschiedene Ausnahmen aus derselben Datei sind ein Problem
     * und nicht fünf.
     *
     * @param  array<int, int>  $counts
     * @return list<array{name: string, count: int}>
     */
    private static function topAreas(array $counts, int $limit): array
    {
        if ($counts === []) {
            return [];
        }

        $areas = [];

        $culprits = Issue::query()
            ->whereIn('id', array_keys($counts))
            ->pluck('culprit', 'id');

        foreach ($counts as $issueId => $count) {
            $name = $culprits[$issueId] ?? null;

            if ($name === null || $name === '') {
                continue;
            }

            $areas[$name] = ($areas[$name] ?? 0) + $count;
        }

        arsort($areas);

        $result = [];

        foreach (array_slice($areas, 0, $limit, true) as $name => $count) {
            $result[] = ['name' => (string) $name, 'count' => $count];
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $counts
     * @return array<int, int>
     */
    private static function highest(array $counts, int $limit): array
    {
        arsort($counts);

        return array_slice($counts, 0, $limit, true);
    }
}
