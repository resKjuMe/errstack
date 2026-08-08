<?php

namespace App\Support\Alerts;

use App\Enums\AlertHistoryState;
use App\Enums\AlertStatus;
use App\Enums\IssueAlertCondition;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertTrigger;
use App\Models\MetricAlert;
use App\Models\MetricAlertTransition;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Formats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Der gemeinsame Verlauf beider Alarm-Arten: was wann gefeuert hat.
 *
 * Die Frage nach einer Störung ist „was war heute Nacht los?" und nicht „was war
 * mit Alarm Nr. 3?" — und sie hört nicht an der Grenze zwischen Schwellwert-
 * Alarmen (A3) und Fehler-Regeln (A2) auf. Beide Verläufe stehen in
 * verschiedenen Tabellen, weil sie Verschiedenes festhalten; **gelesen** werden
 * sie zusammen.
 *
 * **Zwei Abfragen und eine Zusammenführung im Speicher, kein `UNION`.** Die
 * beiden Tabellen haben nicht eine Spalte gemeinsam außer dem Zeitpunkt; ein
 * `UNION` müsste sie auf einen gemeinsamen Satz von Platzhaltern zurechtbiegen
 * und wäre in beiden unterstützten Datenbanken verschieden zu schreiben. Jede
 * Abfrage für sich ist indexgestützt und begrenzt ({@see self::LIMIT}); danach
 * ist es eine Sortierung über höchstens hundert Einträge.
 *
 * **Deshalb kann die Liste kürzer wirken, als sie ist**: von jeder Seite kommen
 * bis zu {@see self::LIMIT} Einträge, gezeigt werden die jüngsten
 * {@see self::LIMIT} der Zusammenführung. Für die Frage „was war los?" ist das
 * richtig; für „wie oft?" gibt es die Grafik, die aus einer eigenen, schmalen
 * Abfrage kommt ({@see self::counts()}).
 */
final class AlertHistory
{
    /**
     * Wie viele Einträge der Verlauf zeigt.
     *
     * Fünfzig sind eine Nacht mit Ärger; mehr liest niemand am Stück, und der
     * Filter über Zeitraum und Zustand ist der bessere Weg zu dem einen Eintrag,
     * den man sucht.
     */
    public const LIMIT = 50;

    /**
     * Wie viele Zeitpunkte die Grafik höchstens zählt.
     *
     * Eine Obergrenze, damit die Seite auch dann eine Abfrage bleibt, wenn
     * jemand neunzig Tage einer flatternden Regel aufschlägt. Wird sie erreicht,
     * sagt die Seite das — eine abgeschnittene Kurve ohne Hinweis wäre eine
     * falsche Auskunft über die Häufigkeit.
     */
    public const COUNT_LIMIT = 5000;

    /**
     * Wie viele Abschnitte die Grafik hat.
     */
    public const BUCKETS = 24;

    public function __construct(
        private readonly Organization $organization,
        private readonly Project $project,
    ) {}

    /**
     * Der Verlauf über alle Regeln des Projekts.
     *
     * @return list<array<string, mixed>>
     */
    public function forProject(CarbonImmutable $since, AlertHistoryState $state): array
    {
        return $this->merge(
            $this->transitions($since, $state, null),
            $this->triggers($since, $state, null),
        );
    }

    /**
     * Der Verlauf eines einzelnen Schwellwert-Alarms.
     *
     * @return list<array<string, mixed>>
     */
    public function forMetricAlert(MetricAlert $alert, CarbonImmutable $since, AlertHistoryState $state): array
    {
        return $this->merge($this->transitions($since, $state, $alert), []);
    }

    /**
     * Der Verlauf einer einzelnen Fehler-Regel.
     *
     * @return list<array<string, mixed>>
     */
    public function forIssueAlertRule(IssueAlertRule $rule, CarbonImmutable $since, AlertHistoryState $state): array
    {
        return $this->merge([], $this->triggers($since, $state, $rule));
    }

    /**
     * Die Grafik: wie viele Einträge in jeden Abschnitt des Zeitraums fallen.
     *
     * Sie kommt aus einer eigenen Abfrage und nicht aus der Liste oben — die ist
     * begrenzt, und aus einer abgeschnittenen Liste gezählte Balken wären eine
     * erfundene Häufigkeit.
     *
     * Gezählt wird im Speicher über die nackten Zeitpunkte statt mit einer
     * Gruppierung in der Datenbank: der Ausdruck für „auf Stunden abrunden"
     * lautet in den beiden unterstützten Datenbanken verschieden, und die Zahl
     * der Zeilen ist hier ausdrücklich gedeckelt.
     *
     * @return array<string, mixed>
     */
    public function counts(
        CarbonImmutable $since,
        CarbonImmutable $until,
        AlertHistoryState $state,
        MetricAlert|IssueAlertRule|null $subject = null,
    ): array {
        if ($subject instanceof IssueAlertRule) {
            $stamps = $this->triggerStamps($since, $until, $state, $subject);
        } elseif ($subject instanceof MetricAlert) {
            $stamps = $this->transitionStamps($since, $until, $state, $subject);
        } else {
            $stamps = [
                ...$this->transitionStamps($since, $until, $state, null),
                ...$this->triggerStamps($since, $until, $state, null),
            ];
        }

        $truncated = count($stamps) >= self::COUNT_LIMIT;

        $seconds = max(1, (int) round($since->diffInSeconds($until) / self::BUCKETS));
        $points = [];

        for ($i = 0; $i < self::BUCKETS; $i++) {
            $from = $since->addSeconds($i * $seconds);

            $points[] = [
                'at' => $from->toIso8601String(),
                'atLabel' => Formats::dateTime($from),
                'value' => 0,
            ];
        }

        foreach ($stamps as $stamp) {
            $index = (int) floor($since->diffInSeconds($stamp) / $seconds);

            // Der letzte Abschnitt nimmt den Rest auf: die Zeitspanne teilt sich
            // selten glatt, und ein Eintrag von vor einer Sekunde gehört in den
            // letzten Balken und nicht in einen, den es nicht gibt.
            $index = max(0, min(self::BUCKETS - 1, $index));

            $points[$index]['value']++;
        }

        return [
            'points' => $points,
            'total' => count($stamps),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $transitions
     * @param  list<array<string, mixed>>  $triggers
     * @return list<array<string, mixed>>
     */
    private function merge(array $transitions, array $triggers): array
    {
        $entries = [...$transitions, ...$triggers];

        usort(
            $entries,
            static fn (array $a, array $b): int => [$b['occurredAt'], $b['id']] <=> [$a['occurredAt'], $a['id']],
        );

        return array_slice($entries, 0, self::LIMIT);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transitions(CarbonImmutable $since, AlertHistoryState $state, ?MetricAlert $alert): array
    {
        if (! $state->includesTransitions()) {
            return [];
        }

        return $this->transitionQuery($since, $state, $alert)
            ->with('alert:id,name,metric,comparison')
            ->latestFirst()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (MetricAlertTransition $transition): array => [
                'id' => 'metric-'.$transition->id,
                'kind' => 'metric',
                'alert' => $transition->alert?->name,
                'alertHref' => $transition->alert === null ? null : route('projects.alert-overview.metric', [
                    $this->organization,
                    $this->project,
                    $transition->alert,
                ]),
                'state' => self::stateOf($transition),
                'stateLabel' => __('alert_overview.states.'.self::stateOf($transition)),
                'kindLabel' => __('alerts.kind.'.$transition->kind()),
                'detail' => Formats::number(
                    $transition->value,
                    $transition->alert?->metric->decimals() ?? 0,
                ),
                'issueHref' => null,
                'deliveryCount' => null,
                'occurredAt' => $transition->occurred_at->toIso8601String(),
                'occurredAtLabel' => Formats::dateTime($transition->occurred_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function triggers(CarbonImmutable $since, AlertHistoryState $state, ?IssueAlertRule $rule): array
    {
        if (! $state->includesIssueTriggers()) {
            return [];
        }

        return $this->triggerQuery($since, $rule)
            ->with(['rule:id,name', 'issue:id,title'])
            ->latestFirst()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (IssueAlertTrigger $trigger): array => [
                'id' => 'issue-'.$trigger->id,
                'kind' => 'issue',
                'alert' => $trigger->rule?->name,
                'alertHref' => $trigger->rule === null ? null : route('projects.alert-overview.issue', [
                    $this->organization,
                    $this->project,
                    $trigger->rule,
                ]),
                'state' => AlertHistoryState::Fired->value,
                'stateLabel' => AlertHistoryState::Fired->label(),
                // Warum sie gegriffen hat — der Regelname allein beantwortet die
                // Rückfrage nicht.
                'kindLabel' => implode(', ', array_map(
                    static fn (IssueAlertCondition $condition): string => $condition->label(),
                    $trigger->conditionTypes(),
                )),
                'detail' => $trigger->issue?->title,
                'issueHref' => $trigger->issue === null ? null : route('issues.show', $trigger->issue),
                // Eine Null ist die aussagekräftigste Zahl der Liste: die Regel
                // hat gegriffen und es ist nichts hinausgegangen — kein aktiver
                // Kanal oder eine Stummschaltung.
                'deliveryCount' => $trigger->delivery_count,
                'occurredAt' => $trigger->occurred_at->toIso8601String(),
                'occurredAtLabel' => Formats::dateTime($trigger->occurred_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function transitionStamps(
        CarbonImmutable $since,
        CarbonImmutable $until,
        AlertHistoryState $state,
        ?MetricAlert $alert,
    ): array {
        if (! $state->includesTransitions()) {
            return [];
        }

        return $this->transitionQuery($since, $state, $alert)
            ->where('occurred_at', '<=', $until)
            ->orderByDesc('occurred_at')
            ->limit(self::COUNT_LIMIT)
            ->pluck('occurred_at')
            ->all();
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function triggerStamps(
        CarbonImmutable $since,
        CarbonImmutable $until,
        AlertHistoryState $state,
        ?IssueAlertRule $rule,
    ): array {
        if (! $state->includesIssueTriggers()) {
            return [];
        }

        return $this->triggerQuery($since, $rule)
            ->where('occurred_at', '<=', $until)
            ->orderByDesc('occurred_at')
            ->limit(self::COUNT_LIMIT)
            ->pluck('occurred_at')
            ->all();
    }

    /**
     * @return Builder<MetricAlertTransition>
     */
    private function transitionQuery(CarbonImmutable $since, AlertHistoryState $state, ?MetricAlert $alert): Builder
    {
        $query = MetricAlertTransition::query()
            ->where('occurred_at', '>=', $since);

        // Ein einzelner Alarm über seine Kennung, sonst alle des Projekts über
        // eine Unterabfrage — dieselbe Form wie auf der Einstellungsseite.
        $alert === null
            ? $query->whereIn('metric_alert_id', $this->project->metricAlerts()->select('id'))
            : $query->where('metric_alert_id', $alert->id);

        $status = $state->transitionStatus();

        if ($status !== null) {
            $query->where('to_status', $status->value);
        }

        return $query;
    }

    /**
     * @return Builder<IssueAlertTrigger>
     */
    private function triggerQuery(CarbonImmutable $since, ?IssueAlertRule $rule): Builder
    {
        $query = IssueAlertTrigger::query()
            ->where('occurred_at', '>=', $since);

        $rule === null
            ? $query->whereIn('issue_alert_rule_id', $this->project->issueAlertRules()->select('id'))
            : $query->where('issue_alert_rule_id', $rule->id);

        return $query;
    }

    /**
     * Der Zustand eines Wechsels, wie ihn der Filter kennt: das **Ziel**, denn
     * danach sucht, wer wissen will, wann es kritisch wurde.
     */
    private static function stateOf(MetricAlertTransition $transition): string
    {
        return match ($transition->to_status) {
            AlertStatus::Ok => AlertHistoryState::Resolved->value,
            AlertStatus::Warning => AlertHistoryState::Warning->value,
            AlertStatus::Critical => AlertHistoryState::Critical->value,
        };
    }
}
