<?php

namespace App\Support\Overviews;

use App\Enums\AlertStatus;
use App\Models\MetricAlert;
use App\Models\Project;
use App\Models\User;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverException;
use App\Support\Filters\GlobalFilter;
use App\Support\QuotaData;

/**
 * Die Startseite der Organisation: was ist los, wo, und wo brennt es.
 *
 * **Fünf Kacheln in der Reihenfolge, in der jemand fragt.** Erst „ist etwas
 * passiert" (die beiden Verläufe), dann „wo" (die Projekte), dann „muss ich
 * etwas tun" (die Alarme), zuletzt „reicht das Kontingent". Eine Übersicht, die
 * mit dem Kontingent beginnt, beantwortet die Frage, die nach dem Anmelden
 * niemand hat.
 *
 * **Jede Kachel hat ihre eigene Adresse.** Sie werden nebeneinander geholt und
 * nicht nacheinander gerechnet — dieselbe Entscheidung wie bei den Dashboards
 * (D4) und der Grund, warum die Seite sofort dasteht und sich füllt.
 */
final class OrganizationOverview
{
    /**
     * Die Kacheln dieser Seite. Was hier nicht steht, gibt es nicht — eine
     * unbekannte Kachel ist eine unbekannte Adresse und keine leere Antwort.
     *
     * @var list<string>
     */
    public const PANELS = ['errors', 'transactions', 'projects', 'alerts', 'quota'];

    /**
     * Wie viele Projekte die Rangliste zeigt. Genug, um zu sehen, wo etwas los
     * ist; wenig genug, dass die Kachel eine Kachel bleibt. Der Weg zu allen
     * steht darunter.
     */
    private const TOP_PROJECTS = 5;

    /**
     * Wie viele offene Alarme die Kachel nennt.
     */
    private const MAX_ALERTS = 5;

    public function __construct(private readonly OverviewEngine $engine = new OverviewEngine) {}

    /**
     * Der Rahmen der Seite: was die Kacheln sind und wo ihre Zahlen stehen.
     *
     * @return array<string, mixed>
     */
    public static function frame(GlobalFilter $filter): array
    {
        return [
            'scope' => [
                'organization' => $filter->organization === null ? null : [
                    'slug' => $filter->organization->slug,
                    'name' => $filter->organization->name,
                ],
                'projects' => $filter->projects->pluck('name')->values()->all(),
                'environment' => $filter->environment,
                'rangeLabel' => $filter->rangeLabel(),
            ],
            'panels' => array_map(
                static fn (string $key): array => [
                    'key' => $key,
                    'href' => route('dashboard.panel', ['panel' => $key] + $filter->formValues()),
                ],
                self::PANELS,
            ),
            // Ohne ein einziges Projekt ist die Übersicht keine leere
            // Auswertung, sondern eine Organisation ohne Projekte — dann führt
            // die Seite dorthin und zeichnet nicht fünf leere Kacheln.
            'projectsHref' => route('projects.index'),
            'hasProjects' => $filter->availableProjects->isNotEmpty(),
        ];
    }

    /**
     * Die Zahlen einer Kachel.
     *
     * @return array<string, mixed>
     */
    public function panel(string $key, GlobalFilter $filter, User $viewer): array
    {
        // Ohne Organisation gibt es keine Zahlen — und niemanden, den das
        // beträfe: die Seite liegt unter der Organisation, und ohne
        // Mitgliedschaft kommt man gar nicht erst hierher.
        if ($filter->organization === null) {
            return OverviewPanel::rows($key, []);
        }

        try {
            return match ($key) {
                'errors' => $this->errors($filter),
                'transactions' => $this->transactions($filter),
                'projects' => $this->projects($filter),
                'alerts' => $this->alerts($filter),
                'quota' => $this->quota($filter, $viewer),
                // Erreichbar ist das nicht — die Route lässt nur die Kacheln
                // aus self::PANELS durch. Es steht hier, damit die Weiche
                // vollständig ist und nicht als Zufall funktioniert.
                default => OverviewPanel::failed($key, ['reason' => 'unknown', 'message' => __('overview.panel.unknown')]),
            };
        } catch (DiscoverException $exception) {
            // Der Motor sagt mit Grenze und verlangtem Wert, warum er nicht
            // gerechnet hat. Die Kachel zeigt das an ihrer Stelle; die übrigen
            // stehen unverändert da.
            return OverviewPanel::failed($key, $exception->toArray());
        }
    }

    /**
     * Der Fehlerverlauf über alle gewählten Projekte.
     *
     * @return array<string, mixed>
     */
    private function errors(GlobalFilter $filter): array
    {
        $series = $this->engine->series(Dataset::Errors, $filter->projectIds(), $filter, 'count()');

        return OverviewPanel::withSetup(
            OverviewPanel::series(
                'errors',
                ['key' => 'count', 'label' => __('overview.organization.errors.metric'), 'format' => 'number', 'unit' => ''],
                $series,
                OverviewLinks::to('issues.index', [], $filter),
            ),
            OverviewSetup::hint($filter->organization, $filter->projects),
        );
    }

    /**
     * Der Transaktionsverlauf — als Durchsatz und nicht als Antwortzeit.
     *
     * Über mehrere Projekte hinweg ist eine gemeinsame p95 keine Zahl: sie
     * ließe sich nur aus den Einzelmessungen rechnen, und das wäre die zweite
     * Auswertungslogik, die es hier nicht geben darf. Der Durchsatz addiert
     * sich dagegen sauber. Die Antwortzeiten stehen eine Ebene tiefer, je
     * Projekt — der Weg dorthin steht an der Kachel.
     *
     * @return array<string, mixed>
     */
    private function transactions(GlobalFilter $filter): array
    {
        // `count()` ist auf dieser Quelle die Summe der Fenster-Zähler und
        // nicht die Zahl der Fenster — die Quelle rechnet es selbst so
        // ({@see App\Support\Discover\Datasets\TransactionWindowFields}).
        $series = $this->engine->series(Dataset::TransactionWindows, $filter->projectIds(), $filter, 'count()');

        return OverviewPanel::withSetup(
            OverviewPanel::series(
                'transactions',
                ['key' => 'count', 'label' => __('overview.organization.transactions.metric'), 'format' => 'number', 'unit' => ''],
                $series,
                OverviewLinks::to('performance.index', [], $filter),
            ),
            OverviewSetup::hint($filter->organization, $filter->projects),
        );
    }

    /**
     * Die Projekte mit den meisten Fehlern im Zeitraum.
     *
     * @return array<string, mixed>
     */
    private function projects(GlobalFilter $filter): array
    {
        $counts = $this->engine->perProject(Dataset::Errors, $filter->projectIds(), $filter, 'count()');
        $pending = OverviewSetup::pendingIds($filter->projects);

        $rows = $filter->projects
            ->map(fn (Project $project): array => [
                'key' => $project->slug,
                'title' => $project->name,
                // Ein Projekt, von dem noch nichts vorliegt, steht mit dem Weg
                // in die Einrichtung da und nicht mit einer Null: die Null
                // sähe aus wie „läuft und macht keine Fehler".
                'pending' => in_array((int) $project->id, $pending, true),
                'href' => in_array((int) $project->id, $pending, true)
                    ? route('projects.setup.index', [$filter->organization, $project])
                    : OverviewLinks::to('projects.overview', [$filter->organization, $project], $filter, [$project->slug]),
                'values' => [[
                    'label' => __('overview.organization.projects.metric'),
                    'value' => $counts[$project->id] ?? null,
                    'format' => 'number',
                    'unit' => '',
                    'href' => OverviewLinks::to('issues.index', [], $filter, [$project->slug]),
                ]],
            ])
            ->sortByDesc(fn (array $row): float => (float) ($row['values'][0]['value'] ?? 0))
            ->take(self::TOP_PROJECTS)
            ->values()
            ->all();

        return OverviewPanel::rows('projects', $rows, route('projects.index'));
    }

    /**
     * Die Alarme, die gerade nicht in Ordnung sind.
     *
     * Ohne Zeitraum, und das ist Absicht: ein Alarm, der seit gestern kritisch
     * steht, verschwindet nicht dadurch aus der Übersicht, dass jemand „letzte
     * Stunde" einstellt. Was gerade offen ist, ist gerade offen.
     *
     * @return array<string, mixed>
     */
    private function alerts(GlobalFilter $filter): array
    {
        $projects = $filter->projects->keyBy('id');

        $alerts = MetricAlert::query()
            ->whereIn('project_id', $filter->projectIds())
            ->where('is_active', true)
            ->whereIn('status', [AlertStatus::Critical, AlertStatus::Warning])
            // Kritisch vor Warnung, und innerhalb dessen das Jüngste zuerst.
            ->orderByRaw('case when status = ? then 0 else 1 end', [AlertStatus::Critical->value])
            ->orderByDesc('status_since')
            ->limit(self::MAX_ALERTS)
            ->get();

        $rows = $alerts
            ->filter(fn (MetricAlert $alert): bool => $projects->has($alert->project_id))
            ->map(function (MetricAlert $alert) use ($projects, $filter): array {
                /** @var Project $project */
                $project = $projects->get($alert->project_id);

                return [
                    'key' => (string) $alert->id,
                    'title' => $alert->name,
                    'subtitle' => $project->name,
                    'tone' => $alert->status === AlertStatus::Critical ? 'critical' : 'warning',
                    'badge' => $alert->status->label(),
                    'href' => OverviewLinks::to(
                        'projects.alert-overview.metric',
                        [$filter->organization, $project, $alert],
                        $filter,
                        [$project->slug],
                    ),
                    'values' => [[
                        'label' => __('overview.organization.alerts.value'),
                        'value' => $alert->last_value,
                        'format' => 'number',
                        'unit' => '',
                    ]],
                ];
            })
            ->values()
            ->all();

        return OverviewPanel::rows('alerts', $rows);
    }

    /**
     * Was vom Kontingent der Organisation verbraucht ist.
     *
     * Die Zahlen kommen aus derselben Quelle wie die Kontingent-Seite und
     * werden hier nur enger gefasst — zwei Rechnungen wären zwei Antworten auf
     * „wie viel ist noch übrig".
     *
     * @return array<string, mixed>
     */
    private function quota(GlobalFilter $filter, User $viewer): array
    {
        if ($filter->organization === null) {
            return OverviewPanel::stats('quota', []);
        }

        $href = route('organizations.quotas.index', $filter->organization);
        $quota = QuotaData::forOrganization($filter->organization, $viewer);

        /** @var list<array<string, mixed>> $categories */
        $categories = $quota['categories'];

        $stats = array_map(static fn (array $category): array => [
            'key' => $category['value'],
            'label' => $category['label'],
            'value' => $category['usage'],
            'format' => 'number',
            'unit' => '',
            'limit' => $category['perMonth'],
            'percent' => $category['percent'],
            'href' => $href,
        ], $categories);

        return OverviewPanel::stats('quota', $stats, $href);
    }
}
