<?php

namespace App\Support\Overviews;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverException;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Die Startseite eines Teams: unsere Projekte, was auf uns wartet, wer woran
 * sitzt.
 *
 * **Sie beantwortet eine andere Frage als die Organisations-Übersicht.** Dort
 * geht es darum, wo etwas los ist; hier darum, was **wir** zu tun haben. Das
 * Unterscheidende ist deshalb nicht die Menge der Fehler, sondern die
 * ungeprüften und die zugewiesenen — Arbeit, die auf jemanden wartet.
 *
 * **Die Projekte des Teams sind die Obergrenze, die Filterleiste schränkt
 * weiter ein.** Wählt jemand in der Leiste ein Projekt, das dem Team nicht
 * gehört, bleibt es draußen: eine Team-Seite, die fremde Zahlen zeigt, ist
 * keine Team-Seite. Wählt er gar nichts, gelten alle Projekte des Teams.
 */
final class TeamOverview
{
    /**
     * @var list<string>
     */
    public const PANELS = ['projects', 'review', 'assignments'];

    /**
     * Wie viele Einträge die Listen zeigen. Der Weg zu allen steht darunter.
     */
    private const MAX_ROWS = 5;

    public function __construct(private readonly OverviewEngine $engine = new OverviewEngine) {}

    /**
     * @return array<string, mixed>
     */
    public static function frame(Team $team, GlobalFilter $filter): array
    {
        $team->loadMissing('organization');

        return [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'settingsHref' => route('teams.show', $team),
            ],
            'scope' => [
                'environment' => $filter->environment,
                'rangeLabel' => $filter->rangeLabel(),
            ],
            'panels' => array_map(
                static fn (string $key): array => [
                    'key' => $key,
                    'href' => route(
                        'teams.overview.panel',
                        [$team->organization, $team, 'panel' => $key] + $filter->formValues(),
                    ),
                ],
                self::PANELS,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function panel(string $key, Team $team, GlobalFilter $filter): array
    {
        $team->loadMissing('organization');
        $projects = self::projectsOf($team, $filter);

        try {
            return match ($key) {
                'projects' => $this->projects($team, $projects, $filter),
                'review' => $this->review($team, $projects, $filter),
                'assignments' => $this->assignments($team, $projects, $filter),
                default => OverviewPanel::failed($key, ['reason' => 'unknown', 'message' => __('overview.panel.unknown')]),
            };
        } catch (DiscoverException $exception) {
            return OverviewPanel::failed($key, $exception->toArray());
        }
    }

    /**
     * Die Projekte des Teams mit ihren Fehlern im Zeitraum.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function projects(Team $team, Collection $projects, GlobalFilter $filter): array
    {
        $ids = $projects->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $counts = $this->engine->perProject(Dataset::Errors, $ids, $filter, 'count()');
        $pending = OverviewSetup::pendingIds($projects);

        $rows = $projects
            ->map(fn (Project $project): array => [
                'key' => $project->slug,
                'title' => $project->name,
                'pending' => in_array((int) $project->id, $pending, true),
                'href' => in_array((int) $project->id, $pending, true)
                    ? route('projects.setup.index', [$team->organization, $project])
                    : OverviewLinks::to('projects.overview', [$team->organization, $project], $filter, [$project->slug]),
                'values' => [[
                    'label' => __('overview.team.projects.metric'),
                    'value' => $counts[$project->id] ?? null,
                    'format' => 'number',
                    'unit' => '',
                    'href' => OverviewLinks::to('issues.index', [], $filter, [$project->slug]),
                ]],
            ])
            ->sortByDesc(fn (array $row): float => (float) ($row['values'][0]['value'] ?? 0))
            ->take(self::MAX_ROWS)
            ->values()
            ->all();

        return OverviewPanel::withSetup(
            OverviewPanel::rows('projects', $rows, route('projects.index')),
            OverviewSetup::hint($team->organization, $projects),
        );
    }

    /**
     * Die ungeprüften Fehler: was neu ist und noch niemand angesehen hat.
     *
     * Das ist die eigentliche Arbeitsliste eines Teams — und der Grund, warum
     * diese Kachel vor den Zuweisungen steht: ein ungeprüfter Fehler hat noch
     * niemanden, der ihn hält.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function review(Team $team, Collection $projects, GlobalFilter $filter): array
    {
        $issues = $this->issues($projects, $filter)
            ->whereNotNull('for_review_at')
            ->orderByDesc('for_review_at')
            ->limit(self::MAX_ROWS)
            ->get();

        return OverviewPanel::rows(
            'review',
            $this->issueRows($issues, $team, fn (Issue $issue): ?string => Formats::dateTime($issue->for_review_at)),
            OverviewLinks::to('issues.index', [], $filter, self::slugs($projects)),
        );
    }

    /**
     * Was dem Team und seinen Mitgliedern zugewiesen ist.
     *
     * Beides in einer Liste: wer wissen will, was auf sein Team wartet, macht
     * keinen Unterschied zwischen „dem Team zugewiesen" und „einem von uns
     * zugewiesen" — die Arbeit liegt in beiden Fällen hier.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function assignments(Team $team, Collection $projects, GlobalFilter $filter): array
    {
        $memberIds = $team->members()->pluck('users.id')->all();

        $issues = $this->issues($projects, $filter)
            ->where(function (Builder $query) use ($team, $memberIds): void {
                $query->where('assigned_team_id', $team->id);

                if ($memberIds !== []) {
                    $query->orWhereIn('assigned_user_id', $memberIds);
                }
            })
            ->orderByDesc('assigned_at')
            ->limit(self::MAX_ROWS)
            ->get();

        return OverviewPanel::rows(
            'assignments',
            $this->issueRows(
                $issues->loadMissing('assignedUser', 'assignedTeam'),
                $team,
                fn (Issue $issue): ?string => $issue->assignedUser?->name ?? $issue->assignedTeam?->name,
            ),
            OverviewLinks::to('issues.index', [], $filter, self::slugs($projects)),
        );
    }

    /**
     * Die offenen Fehler der Team-Projekte im Zeitraum — die gemeinsame
     * Grundabfrage der beiden Arbeitslisten.
     *
     * Gefragt wird nach der Überschneidung mit dem Zeitraum und nicht nach dem
     * letzten Auftreten darin: dieselbe Regel wie in der Fehlerliste.
     *
     * @param  Collection<int, Project>  $projects
     * @return Builder<Issue>
     */
    private function issues(Collection $projects, GlobalFilter $filter): Builder
    {
        return Issue::query()
            ->whereIn('project_id', $projects->pluck('id')->all())
            ->open()
            ->standalone()
            ->where('last_seen', '>=', $filter->fromUtc())
            ->where('first_seen', '<=', $filter->toUtc());
    }

    /**
     * @param  Collection<int, Issue>  $issues
     * @param  callable(Issue): ?string  $subtitle
     * @return list<array<string, mixed>>
     */
    private function issueRows(Collection $issues, Team $team, callable $subtitle): array
    {
        return $issues->map(fn (Issue $issue): array => [
            'key' => (string) $issue->id,
            'title' => (string) ($issue->title ?? $issue->culprit ?? ''),
            'subtitle' => $subtitle($issue),
            'badge' => $issue->level->label(),
            'href' => route('issues.show', [$team->organization, $issue]),
            'values' => [[
                'label' => __('overview.team.issues.times_seen'),
                'value' => (float) $issue->times_seen,
                'format' => 'number',
                'unit' => '',
            ]],
        ])->values()->all();
    }

    /**
     * Die Projekte, über die diese Seite spricht: die des Teams, durch die
     * Filterleiste weiter eingeschränkt.
     *
     * @return Collection<int, Project>
     */
    private static function projectsOf(Team $team, GlobalFilter $filter): Collection
    {
        $selected = $filter->projectIds();

        /** @var Collection<int, Project> $projects */
        $projects = $team->projects()->orderBy('name')->get();

        return $projects->filter(
            fn (Project $project): bool => in_array((int) $project->id, $selected, true),
        )->values();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return list<string>
     */
    private static function slugs(Collection $projects): array
    {
        return $projects->pluck('slug')->map(fn (mixed $slug): string => (string) $slug)->values()->all();
    }
}
