<?php

namespace App\Support\Overviews;

use App\Models\Issue;
use App\Models\OwnershipRule;
use App\Models\Project;
use App\Models\Release;
use App\Models\Team;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverException;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Releases\Health\ReleaseHealth;
use Illuminate\Support\Collection;

/**
 * Die Startseite eines Projekts: Verlauf, was zuletzt kaputtging, wie die
 * letzte Auslieferung läuft — und wer zuständig ist.
 *
 * **Sie ist nicht die Projekt-Einstellungsseite.** Dort wird eingerichtet, hier
 * wird nachgesehen; das ist derselbe Schnitt wie bei der Alarm-Übersicht. Wer
 * nach einer Meldung hierherkommt, sucht nicht die Aufbewahrungsfrist.
 *
 * **Der Filter gilt auch hier.** Zeitraum und Umgebung kommen aus der Leiste
 * wie auf jeder Auswertungsseite; die Projektauswahl ist auf dieses eine
 * Projekt festgelegt — es steht in der Adresse. Eine Seite, die „Projekt X"
 * heißt und Zahlen von Y zeigt, weil die Leiste anders eingestellt war, wäre
 * die schlimmste der möglichen Verwechslungen.
 */
final class ProjectOverview
{
    /**
     * @var list<string>
     */
    public const PANELS = ['errors', 'issues', 'releases', 'ownership'];

    /**
     * Wie viele Fehler-Einträge die Liste zeigt. Der Weg zu allen steht
     * darunter.
     */
    private const MAX_ISSUES = 5;

    /**
     * Wie viele Auslieferungen die Gesundheits-Kachel vergleicht.
     */
    private const MAX_RELEASES = 3;

    public function __construct(
        private readonly OverviewEngine $engine = new OverviewEngine,
        private readonly ReleaseHealth $health = new ReleaseHealth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function frame(Project $project, GlobalFilter $filter): array
    {
        $project->loadMissing('organization');

        return [
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'platformLabel' => $project->platform->label(),
                'settingsHref' => route('projects.show', [$project->organization, $project]),
                'issuesHref' => OverviewLinks::to('issues.index', [], $filter, [$project->slug]),
                'alertsHref' => OverviewLinks::to(
                    'projects.alert-overview.index',
                    [$project->organization, $project],
                    $filter,
                    [$project->slug],
                ),
            ],
            'scope' => [
                'environment' => $filter->environment,
                'rangeLabel' => $filter->rangeLabel(),
            ],
            'panels' => array_map(
                static fn (string $key): array => [
                    'key' => $key,
                    'href' => route(
                        'projects.overview.panel',
                        [$project->organization, $project, 'panel' => $key] + $filter->formValues(),
                    ),
                ],
                self::PANELS,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function panel(string $key, Project $project, GlobalFilter $filter): array
    {
        try {
            return match ($key) {
                'errors' => $this->errors($project, $filter),
                'issues' => $this->issues($project, $filter),
                'releases' => $this->releases($project, $filter),
                'ownership' => $this->ownership($project),
                default => OverviewPanel::failed($key, ['reason' => 'unknown', 'message' => __('overview.panel.unknown')]),
            };
        } catch (DiscoverException $exception) {
            return OverviewPanel::failed($key, $exception->toArray());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errors(Project $project, GlobalFilter $filter): array
    {
        $series = $this->engine->series(Dataset::Errors, [$project->id], $filter, 'count()');

        return OverviewPanel::withSetup(
            OverviewPanel::series(
                'errors',
                ['key' => 'count', 'label' => __('overview.project.errors.metric'), 'format' => 'number', 'unit' => ''],
                $series,
                OverviewLinks::to('issues.index', [], $filter, [$project->slug]),
            ),
            OverviewSetup::hint($project->organization, new Collection([$project])),
        );
    }

    /**
     * Die zuletzt aufgetretenen offenen Fehler dieses Projekts.
     *
     * Gefragt wird nach der **Überschneidung** mit dem Zeitraum und nicht nach
     * dem letzten Auftreten darin: ein Fehler, den es letzte Woche gab und
     * heute wieder gibt, gehört in „letzte Woche" — dieselbe Regel wie in der
     * Fehlerliste ({@see GlobalFilter::overlapping()}).
     *
     * @return array<string, mixed>
     */
    private function issues(Project $project, GlobalFilter $filter): array
    {
        $issues = Issue::query()
            ->where('project_id', $project->id)
            ->open()
            ->standalone()
            ->where('last_seen', '>=', $filter->fromUtc())
            ->where('first_seen', '<=', $filter->toUtc())
            ->latestFirst()
            ->limit(self::MAX_ISSUES)
            ->get();

        $rows = $issues->map(fn (Issue $issue): array => [
            'key' => (string) $issue->id,
            'title' => (string) ($issue->title ?? $issue->culprit ?? ''),
            'subtitle' => (string) $issue->culprit,
            'badge' => $issue->level->label(),
            'href' => route('issues.show', [$project->organization, $issue]),
            'values' => [
                [
                    'label' => __('overview.project.issues.times_seen'),
                    'value' => (float) $issue->times_seen,
                    'format' => 'number',
                    'unit' => '',
                ],
                [
                    'label' => __('overview.project.issues.users_seen'),
                    'value' => (float) $issue->users_seen,
                    'format' => 'number',
                    'unit' => '',
                ],
            ],
        ])->values()->all();

        return OverviewPanel::withSetup(
            OverviewPanel::rows('issues', $rows, OverviewLinks::to('issues.index', [], $filter, [$project->slug])),
            OverviewSetup::hint($project->organization, new Collection([$project])),
        );
    }

    /**
     * Die Gesundheit der letzten Auslieferungen.
     *
     * Die Zahlen kommen aus {@see ReleaseHealth} und werden hier nicht
     * nachgerechnet: „absturzfrei" soll auf der Übersicht dasselbe heißen wie
     * auf der Auslieferungsseite und im Alarm.
     *
     * @return array<string, mixed>
     */
    private function releases(Project $project, GlobalFilter $filter): array
    {
        $releases = Release::query()
            ->where('project_id', $project->id)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->limit(self::MAX_RELEASES)
            ->get();

        $rows = $releases->map(function (Release $release) use ($project, $filter): array {
            $summary = $this->health->summarize(
                $release,
                $filter->fromUtc(),
                $filter->toUtc(),
                $filter->environment,
            );

            return [
                'key' => (string) $release->id,
                'title' => $release->version,
                'subtitle' => Formats::dateTime($release->released_at ?? $release->first_event_at),
                'href' => OverviewLinks::to(
                    'releases.show',
                    [$project->organization, $release],
                    $filter,
                    [$project->slug],
                ),
                'values' => [
                    [
                        'label' => __('overview.project.releases.crash_free'),
                        'value' => $summary->crashFreeSessions(),
                        'format' => 'percent',
                        'unit' => '%',
                    ],
                    [
                        'label' => __('overview.project.releases.adoption'),
                        'value' => $summary->adoptionUsers(),
                        'format' => 'percent',
                        'unit' => '%',
                    ],
                ],
            ];
        })->values()->all();

        return OverviewPanel::rows(
            'releases',
            $rows,
            OverviewLinks::to('releases.index', [], $filter, [$project->slug]),
        );
    }

    /**
     * Wer für dieses Projekt zuständig ist.
     *
     * Keine Auswertung, sondern die Antwort auf „wen frage ich" — und der Weg
     * dorthin, wo sich das ändern lässt. Sie steht ohne Zeitraum da: eine
     * Zuständigkeit gilt jetzt und nicht letzte Woche.
     *
     * @return array<string, mixed>
     */
    private function ownership(Project $project): array
    {
        $project->loadMissing('organization', 'teams');

        /** @var Collection<int, Team> $teams */
        $teams = $project->teams;

        $rows = $teams->map(fn (Team $team): array => [
            'key' => (string) $team->id,
            'title' => $team->name,
            'href' => route('teams.overview', [$project->organization, $team]),
            'values' => [],
        ])->values()->all();

        $rules = OwnershipRule::query()
            ->where('project_id', $project->id)
            ->active()
            ->count();

        $ownershipHref = route('projects.ownership.index', [$project->organization, $project]);

        return OverviewPanel::rows('ownership', $rows, $ownershipHref, [
            [
                'key' => 'teams',
                'label' => __('overview.project.ownership.teams'),
                'value' => (float) $teams->count(),
                'format' => 'number',
                'unit' => '',
                'href' => route('projects.show', [$project->organization, $project]),
            ],
            [
                'key' => 'rules',
                'label' => __('overview.project.ownership.rules'),
                'value' => (float) $rules,
                'format' => 'number',
                'unit' => '',
                'href' => $ownershipHref,
            ],
        ]);
    }
}
