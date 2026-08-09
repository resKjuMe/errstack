<?php

namespace App\Http\Controllers;

use App\Enums\AlertSnoozeScope;
use App\Enums\DeliveryStatus;
use App\Enums\IssueAlertAction;
use App\Models\AlertSnooze;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertTrigger;
use App\Models\MetricAlert;
use App\Models\MetricAlertTransition;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Alerts\AlertFilter;
use App\Support\Alerts\AlertHistory;
use App\Support\Alerts\AlertMute;
use App\Support\Alerts\AlertReference;
use App\Support\Alerts\MetricAlertPreview;
use App\Support\Formats;
use App\Support\IssueAlerts\RuleAction;
use App\Support\IssueAlerts\RuleCondition;
use App\Support\IssueAlerts\RuleFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Alarm-Übersicht eines Projekts: was hat wann gefeuert — und was ist
 * gerade still?
 *
 * **Sie ist keine Einstellungsseite.** Eingerichtet werden Alarme dort, wo sie
 * hingehören: Schwellwerte in A3, Fehler-Regeln in A2. Hier wird
 * **nachgesehen**, und zwar in der Reihenfolge, in der jemand nach einer
 * Störung fragt: Welche Regeln gibt es? Welche hat gefeuert? Was ist daraufhin
 * hinausgegangen — und kam es an?
 *
 * **Beide Arten in einer Liste.** Das ist der eigentliche Zweck: wer eine
 * Benachrichtigung bekommen hat, weiß nicht, ob dahinter ein Schwellwert oder
 * eine Fehler-Regel steckte, und soll es auch nicht wissen müssen. Sie stehen in
 * verschiedenen Tabellen, weil sie Verschiedenes festhalten; gelesen werden sie
 * zusammen ({@see AlertHistory}).
 *
 * Ansehen darf jedes Mitglied — dieselbe Begründung wie bei den
 * Einstellungsseiten: die erste Frage nach einer ausgebliebenen Meldung ist,
 * welche Regeln überhaupt scharf sind, und die stellt nicht nur die Verwaltung.
 */
class AlertOverviewController extends Controller
{
    /**
     * Wie viele Zustellungen die Detailseite zeigt.
     *
     * Genug, um zu sehen, ob ein Kanal klemmt; wenig genug, dass die Seite eine
     * Abfrage bleibt. Das vollständige Protokoll steht bei den
     * Benachrichtigungswegen (A1).
     */
    private const DELIVERY_LIMIT = 25;

    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        $filter = AlertFilter::fromRequest($request);
        $history = new AlertHistory($organization, $project);

        return Inertia::render('projects/AlertOverview', [
            ...$this->shell($organization, $project),
            'alertFilter' => $filter->toArray(),
            'rows' => $this->rows($request, $organization, $project, $filter),
            'history' => $history->forProject($filter->from, $filter->state),
            'chart' => $history->counts($filter->from, $filter->to, $filter->state),
            'snooze' => $this->snoozeOptions(),
            'canManage' => Gate::allows('manageAlerts', $project),
        ]);
    }

    /**
     * Ein einzelner Schwellwert-Alarm.
     *
     * Die Kurve daneben ist dieselbe wie in der Vorschau der Einstellungsseite
     * ({@see MetricAlertPreview}) — und das ist der Punkt: sie rechnet mit
     * derselben Ablesung wie der Alarm selbst. Eine Grafik, die anders rechnet
     * als die Auswertung, zeigt einen Verlauf, unter dem etwas anderes passiert
     * ist.
     */
    public function metricAlert(
        Request $request,
        Organization $organization,
        Project $project,
        MetricAlert $metric_alert,
        MetricAlertPreview $preview,
    ): InertiaResponse {
        Gate::authorize('view', $project);

        $filter = AlertFilter::fromRequest($request);
        $history = new AlertHistory($organization, $project);
        $viewer = $request->user()?->id;

        return Inertia::render('projects/AlertDetail', [
            ...$this->shell($organization, $project),
            'alertFilter' => $filter->toArray(),
            'alert' => [
                ...$this->metricRow(
                    $organization,
                    $project,
                    $metric_alert,
                    AlertMute::for($metric_alert),
                    $viewer,
                    $this->statsFor('metric_alert_id', [$metric_alert->id], $filter->from)[$metric_alert->id] ?? null,
                ),
                'facts' => $this->metricFacts($metric_alert),
                // Welche Wege eine Meldung nimmt: ein Schwellwert-Alarm meldet
                // an alle aktiven Kanäle der Organisation und an niemanden
                // persönlich. Deshalb steht hier eine feste Aussage und keine
                // Liste eingestellter Aktionen — es gibt keine.
                'actions' => [__('alert_overview.actions.all_channels')],
            ],
            'history' => $history->forMetricAlert($metric_alert, $filter->from, $filter->state),
            'chart' => $history->counts($filter->from, $filter->to, $filter->state, $metric_alert),
            'metricChart' => $preview->build($metric_alert),
            'deliveries' => $this->deliveries(
                fn (Builder $query) => $query->where('reference', AlertReference::forMetricAlert($metric_alert)),
            ),
            'snooze' => $this->snoozeOptions(),
            'canManage' => Gate::allows('manageAlerts', $project),
        ]);
    }

    /**
     * Eine einzelne Fehler-Regel.
     */
    public function issueAlertRule(
        Request $request,
        Organization $organization,
        Project $project,
        IssueAlertRule $issue_alert_rule,
    ): InertiaResponse {
        Gate::authorize('view', $project);

        $filter = AlertFilter::fromRequest($request);
        $history = new AlertHistory($organization, $project);
        $viewer = $request->user()?->id;

        return Inertia::render('projects/AlertDetail', [
            ...$this->shell($organization, $project),
            'alertFilter' => $filter->toArray(),
            'alert' => [
                ...$this->issueRow(
                    $organization,
                    $project,
                    $issue_alert_rule,
                    AlertMute::for($issue_alert_rule),
                    $viewer,
                    $this->statsFor('issue_alert_rule_id', [$issue_alert_rule->id], $filter->from)[$issue_alert_rule->id] ?? null,
                ),
                'facts' => $this->issueFacts($issue_alert_rule),
                'actions' => array_map(
                    static fn (RuleAction $action): string => $action->type->label(),
                    $issue_alert_rule->parsedActions(),
                ),
            ],
            'history' => $history->forIssueAlertRule($issue_alert_rule, $filter->from, $filter->state),
            'chart' => $history->counts($filter->from, $filter->to, $filter->state, $issue_alert_rule),
            'metricChart' => null,
            // Alle Meldungen dieser Regel, über alle Fehler hinweg — deshalb der
            // gemeinsame Anfang der Kennung und kein einzelner Wert.
            'deliveries' => $this->deliveries(
                fn (Builder $query) => $query->where(
                    'reference',
                    'like',
                    AlertReference::issueAlertPrefix($issue_alert_rule).'%',
                ),
            ),
            'snooze' => $this->snoozeOptions(),
            'canManage' => Gate::allows('manageAlerts', $project),
        ]);
    }

    /**
     * Was jede Seite dieser Ansicht braucht.
     *
     * @return array<string, mixed>
     */
    private function shell(Organization $organization, Project $project): array
    {
        return [
            'organization' => [
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'overviewHref' => route('projects.alert-overview.index', [$organization, $project]),
                // Die Wege zum Einrichten: hier wird nur nachgesehen, und wer
                // etwas ändern will, soll nicht suchen müssen.
                'metricAlertsHref' => route('projects.alerts.index', [$organization, $project]),
                'issueAlertsHref' => route('projects.issue-alerts.index', [$organization, $project]),
            ],
        ];
    }

    /**
     * Alle Regeln des Projekts — beide Arten, ein Name je Zeile.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Request $request, Organization $organization, Project $project, AlertFilter $filter): array
    {
        $viewer = $request->user()?->id;

        $metricAlerts = $project->metricAlerts()->orderBy('name')->get();
        $issueRules = $project->issueAlertRules()->orderBy('name')->get();

        $metricIds = array_map(intval(...), $metricAlerts->modelKeys());
        $issueIds = array_map(intval(...), $issueRules->modelKeys());

        $metricMutes = AlertMute::forMetricAlerts($metricIds);
        $issueMutes = AlertMute::forIssueAlertRules($issueIds);

        $metricStats = $this->statsFor('metric_alert_id', $metricIds, $filter->from);
        $issueStats = $this->statsFor('issue_alert_rule_id', $issueIds, $filter->from);

        $rows = [
            ...$metricAlerts->map(fn (MetricAlert $alert): array => $this->metricRow(
                $organization,
                $project,
                $alert,
                $metricMutes[$alert->id] ?? null,
                $viewer,
                $metricStats[$alert->id] ?? null,
            ))->all(),
            ...$issueRules->map(fn (IssueAlertRule $rule): array => $this->issueRow(
                $organization,
                $project,
                $rule,
                $issueMutes[$rule->id] ?? null,
                $viewer,
                $issueStats[$rule->id] ?? null,
            ))->all(),
        ];

        // Sortiert wird nach dem, wonach man sucht: was zuletzt gefeuert hat,
        // steht oben. Regeln, die noch nie etwas gemeldet haben, danach nach
        // Namen — sie sind die Antwort auf „warum kam nichts?" und gehören nicht
        // ans Ende einer langen Liste, sondern in ihre eigene Ordnung.
        usort($rows, static fn (array $a, array $b): int => [$b['lastAt'] ?? '', $a['name']]
            <=> [$a['lastAt'] ?? '', $b['name']]);

        return $rows;
    }

    /**
     * @param  array{count: int, last: ?CarbonImmutable}|null  $stats
     * @return array<string, mixed>
     */
    private function metricRow(
        Organization $organization,
        Project $project,
        MetricAlert $alert,
        ?AlertMute $mute,
        ?int $viewer,
        ?array $stats,
    ): array {
        // Ein abgeschalteter Alarm hat keinen Zustand mehr, den man ernst nehmen
        // dürfte: er wird nicht ausgewertet. „In Ordnung" wäre hier die
        // gefährlichste Auskunft der Seite.
        $state = $alert->is_active ? $alert->status->value : 'off';

        return [
            'key' => 'metric-'.$alert->id,
            'kind' => 'metric',
            'kindLabel' => __('alert_overview.kinds.metric'),
            'id' => $alert->id,
            'name' => $alert->name,
            'active' => $alert->is_active,
            'state' => $state,
            'stateLabel' => $alert->is_active ? $alert->status->label() : __('alert_overview.states.off'),
            'subtitle' => __('alerts.list.subtitle', [
                'metric' => $alert->metric->label(),
                'minutes' => (string) $alert->window_minutes,
            ]),
            'stateSinceLabel' => Formats::dateTime($alert->status_since),
            'lastAt' => ($stats['last'] ?? null)?->toIso8601String(),
            'lastAtLabel' => Formats::dateTime($stats['last'] ?? null),
            'countInPeriod' => $stats['count'] ?? 0,
            'detailHref' => route('projects.alert-overview.metric', [$organization, $project, $alert]),
            'configHref' => route('projects.alerts.index', [$organization, $project]),
            'snooze' => $this->snoozeState(
                $mute,
                $viewer,
                route('projects.alerts.snooze.store', [$organization, $project, $alert]),
                route('projects.alerts.snooze.destroy', [$organization, $project, $alert]),
                // Ein Schwellwert-Alarm meldet ausschließlich an gemeinsame
                // Kanäle. Eine persönliche Stummschaltung kann daran nichts
                // leiser machen — und die Seite sagt das, statt einen Knopf
                // anzubieten, der nichts tut.
                false,
            ),
        ];
    }

    /**
     * @param  array{count: int, last: ?CarbonImmutable}|null  $stats
     * @return array<string, mixed>
     */
    private function issueRow(
        Organization $organization,
        Project $project,
        IssueAlertRule $rule,
        ?AlertMute $mute,
        ?int $viewer,
        ?array $stats,
    ): array {
        $personal = false;

        foreach ($rule->parsedActions() as $action) {
            if ($action->type === IssueAlertAction::Members) {
                $personal = true;
            }
        }

        return [
            'key' => 'issue-'.$rule->id,
            'kind' => 'issue',
            'kindLabel' => __('alert_overview.kinds.issue'),
            'id' => $rule->id,
            'name' => $rule->name,
            'active' => $rule->is_active,
            // Eine Fehler-Regel hat keinen Zustand im Sinne von A3 — sie ist
            // scharf oder sie ist es nicht. Das ist die ehrliche Auskunft; ein
            // erfundenes „in Ordnung" wäre eine andere.
            'state' => $rule->is_active ? 'armed' : 'off',
            'stateLabel' => __('alert_overview.states.'.($rule->is_active ? 'armed' : 'off')),
            'subtitle' => __('alert_overview.list.frequency', [
                'minutes' => (string) $rule->frequency_minutes,
            ]),
            'stateSinceLabel' => null,
            'lastAt' => ($stats['last'] ?? null)?->toIso8601String(),
            'lastAtLabel' => Formats::dateTime($stats['last'] ?? null),
            'countInPeriod' => $stats['count'] ?? 0,
            'detailHref' => route('projects.alert-overview.issue', [$organization, $project, $rule]),
            'configHref' => route('projects.issue-alerts.index', [$organization, $project]),
            'snooze' => $this->snoozeState(
                $mute,
                $viewer,
                route('projects.issue-alerts.snooze.store', [$organization, $project, $rule]),
                route('projects.issue-alerts.snooze.destroy', [$organization, $project, $rule]),
                $personal,
            ),
        ];
    }

    /**
     * Was gerade still ist — und wo man daran drehen kann.
     *
     * @return array<string, mixed>
     */
    private function snoozeState(
        ?AlertMute $mute,
        ?int $viewer,
        string $storeHref,
        string $destroyHref,
        bool $personalEffective,
    ): array {
        $everyone = $mute?->everyone;
        $mine = $viewer === null ? null : $mute?->mine($viewer);

        return [
            'everyone' => $everyone === null ? null : [
                'untilLabel' => Formats::dateTime($everyone->until),
                'by' => $everyone->createdBy?->name,
            ],
            'mine' => $mine === null ? null : [
                'untilLabel' => Formats::dateTime($mine->until),
            ],
            'storeHref' => $storeHref,
            'destroyHref' => $destroyHref,
            // Ob eine persönliche Stummschaltung überhaupt etwas bewirken kann.
            // Sie tut es nur bei einer Regel, die auch persönlich meldet — sonst
            // gehen alle Meldungen an gemeinsame Kanäle, und die schweigen erst,
            // wenn sie für alle stummgeschaltet werden.
            'personalEffective' => $personalEffective,
        ];
    }

    /**
     * Die Kennzahlen einer Handvoll Regeln — je Art aus ihrer eigenen Tabelle.
     *
     * @param  list<int>  $ids
     * @return array<int, array{count: int, last: ?CarbonImmutable}>
     */
    private function statsFor(string $column, array $ids, CarbonImmutable $since): array
    {
        if ($ids === []) {
            return [];
        }

        $query = $column === 'metric_alert_id'
            ? MetricAlertTransition::query()->whereIn($column, $ids)->toBase()
            : IssueAlertTrigger::query()->whereIn($column, $ids)->toBase();

        return $this->stats($query, $column, $since);
    }

    /**
     * Wann zuletzt und wie oft im Zeitraum — für alle Regeln in **einer**
     * Abfrage.
     *
     * Zwei Fragen mit verschiedenen Grenzen: „zuletzt" gilt ohne Zeitraum (sonst
     * stünde bei einer Regel, die seit drei Tagen ruhig ist, im 24-Stunden-Bild
     * „noch nie"), „wie oft" nur im gewählten. Beides in einer Abfrage, weil der
     * Unterschied eine Bedingung in der Summe ist und keine zweite Runde zur
     * Datenbank.
     *
     * @return array<int, array{count: int, last: ?CarbonImmutable}>
     */
    private function stats(QueryBuilder $query, string $column, CarbonImmutable $since): array
    {
        $rows = $query
            ->selectRaw($column.' as owner')
            ->selectRaw('max(occurred_at) as last_at')
            ->selectRaw('sum(case when occurred_at >= ? then 1 else 0 end) as total', [$since])
            ->groupBy($column)
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(int) $row->owner] = [
                'count' => (int) $row->total,
                'last' => $row->last_at === null ? null : CarbonImmutable::parse($row->last_at),
            ];
        }

        return $stats;
    }

    /**
     * Was aus dieser Regel hinausgegangen ist — und ob es ankam.
     *
     * Erfasst sind die Zustellungen an die gemeinsamen Kanäle (A1). Persönliche
     * Benachrichtigungen stehen nicht darin: sie gehen als Mail an den Einzelnen
     * und werden nicht protokolliert. Die Seite sagt das, statt eine leere
     * Liste als „nichts verschickt" durchgehen zu lassen.
     *
     * @param  callable(Builder<NotificationDelivery>): Builder<NotificationDelivery>  $scope
     * @return list<array<string, mixed>>
     */
    private function deliveries(callable $scope): array
    {
        $query = NotificationDelivery::query()->with('channel:id,name,type');

        return $scope($query)
            ->orderByDesc('id')
            ->limit(self::DELIVERY_LIMIT)
            ->get()
            ->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'channel' => $delivery->channel?->name,
                'subject' => $delivery->subject,
                'status' => $delivery->status->value,
                'statusLabel' => $delivery->status->label(),
                'attempts' => $delivery->attempts,
                'error' => $delivery->status === DeliveryStatus::Failed ? $delivery->error : null,
                'createdAtLabel' => Formats::dateTime($delivery->created_at),
                'deliveredAtLabel' => Formats::dateTime($delivery->delivered_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Die Eckdaten eines Schwellwert-Alarms, wie sie auf der Detailseite stehen.
     *
     * @return list<array{label: string, value: string}>
     */
    private function metricFacts(MetricAlert $alert): array
    {
        $facts = [
            ['label' => __('alerts.fields.metric'), 'value' => $alert->metric->label()],
            ['label' => __('alerts.fields.direction'), 'value' => $alert->direction->label()],
            ['label' => __('alerts.fields.comparison'), 'value' => $alert->comparison->label()],
            [
                'label' => __('alert_overview.facts.window'),
                'value' => __('alerts.notification.minutes', ['minutes' => (string) $alert->window_minutes]),
            ],
        ];

        foreach ([
            'alerts.fields.warning' => $alert->warning_threshold,
            'alerts.fields.critical' => $alert->critical_threshold,
            'alerts.fields.resolve' => $alert->resolve_threshold,
        ] as $key => $threshold) {
            if ($threshold === null) {
                continue;
            }

            $facts[] = [
                'label' => __($key),
                'value' => trim(Formats::number($threshold, $alert->metric->decimals()).' '.$alert->unit()),
            ];
        }

        if ($alert->environment !== null) {
            $facts[] = ['label' => __('alerts.fields.environment'), 'value' => $alert->environment];
        }

        if ($alert->transaction_name !== null) {
            $facts[] = ['label' => __('alerts.fields.transaction'), 'value' => $alert->transaction_name];
        }

        $facts[] = [
            'label' => __('alerts.list.last_evaluated'),
            'value' => Formats::dateTime($alert->last_evaluated_at) ?? (string) __('alerts.list.never_evaluated'),
        ];

        return $facts;
    }

    /**
     * Die Eckdaten einer Fehler-Regel.
     *
     * @return list<array{label: string, value: string}>
     */
    private function issueFacts(IssueAlertRule $rule): array
    {
        $conditions = array_map(
            static fn (RuleCondition $condition): string => $condition->type->label(),
            $rule->parsedConditions(),
        );

        $filters = array_map(
            static fn (RuleFilter $filter): string => $filter->type->label(),
            $rule->parsedFilters(),
        );

        return [
            [
                'label' => __('alert_overview.facts.conditions'),
                'value' => $conditions === [] ? __('alert_overview.facts.none') : implode(', ', $conditions),
            ],
            [
                'label' => __('alert_overview.facts.filters'),
                'value' => $filters === [] ? __('alert_overview.facts.none') : implode(', ', $filters),
            ],
            [
                'label' => __('alert_overview.facts.frequency'),
                'value' => __('alerts.notification.minutes', ['minutes' => (string) $rule->frequency_minutes]),
            ],
        ];
    }

    /**
     * Die Auswahl für die Stummschaltung — Dauern und Geltungsbereiche.
     *
     * Sie kommt vom Server, damit das Formular genau die Werte trägt, die auch
     * angenommen werden ({@see App\Http\Requests\AlertSnoozeRequest}).
     *
     * @return array<string, mixed>
     */
    private function snoozeOptions(): array
    {
        return [
            'durations' => array_map(static fn (int $minutes): array => [
                'value' => $minutes,
                'label' => __('alert_overview.durations.'.$minutes),
            ], AlertSnooze::DURATIONS),
            'scopeOptions' => AlertSnoozeScope::options(),
        ];
    }
}
