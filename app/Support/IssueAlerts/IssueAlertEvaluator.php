<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueAlertCondition;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertState;
use App\Models\IssueAlertTrigger;
use Illuminate\Support\Carbon;

/**
 * Die Auswertung der Alarm-Regeln eines Projekts für ein einzelnes Ereignis.
 *
 * Die Reihenfolge ist der ganze Inhalt dieser Klasse, und sie ist umgekehrt zu
 * dem, was man zuerst schreiben würde:
 *
 *   1. **Begrenzung** — hat diese Regel für diesen Fehler kürzlich gemeldet?
 *   2. **Bedingungen** — die billigen zuerst, die zählenden zuletzt.
 *   3. **Filter** — nur noch für die Fälle, die bis hierher kommen.
 *   4. **Anspruch** — die bedingte Anweisung, die genau einen Melder bestimmt.
 *
 * Die Begrenzung **vorne** und nicht hinten: sie ist gegen Fehlerfluten
 * gemacht, und genau dort wäre sie am Ende die teuerste Stelle — tausend
 * Meldungen je Minute würden tausendmal zählen, filtern und dann verworfen.
 * Vorne ist derselbe Fall ein Blick auf eine indizierte Zeile.
 *
 * Der Anspruch wird trotzdem **erneut** geprüft (Schritt 4). Schritt 1 ist eine
 * Lesung und damit nur eine Momentaufnahme; zwischen ihr und dem Melden kann
 * ein anderer Arbeiter dieselbe Regel bedient haben. Erst die bedingte
 * Anweisung entscheidet — Schritt 1 spart Arbeit, Schritt 4 hält die Zusage.
 */
final class IssueAlertEvaluator
{
    public function __construct(
        private readonly IssueAlertConditions $conditions,
        private readonly IssueAlertFilters $filters,
        private readonly IssueAlertNotifier $notifier,
    ) {}

    /**
     * Wertet alle aktiven Regeln des Projekts aus und meldet, was greift.
     *
     * @return list<IssueAlertTrigger>
     */
    public function evaluate(IssueAlertContext $context): array
    {
        $rules = IssueAlertRule::query()
            ->activeFor($context->issue->project_id)
            ->get();

        $triggers = [];

        foreach ($rules as $rule) {
            $trigger = $this->evaluateRule($rule, $context);

            if ($trigger !== null) {
                $triggers[] = $trigger;
            }
        }

        return $triggers;
    }

    private function evaluateRule(IssueAlertRule $rule, IssueAlertContext $context): ?IssueAlertTrigger
    {
        $now = Carbon::now();

        if ($this->throttled($rule, $context, $now)) {
            return null;
        }

        $matched = $this->conditions->matched(
            $rule->parsedConditions(),
            $rule->condition_match,
            $context,
        );

        if ($matched === []) {
            return null;
        }

        if (! $this->filters->passes($rule->parsedFilters(), $rule->filter_match, $context)) {
            return null;
        }

        // Die Rückfall-Marke wird nur verbraucht, wenn der Rückfall auch ein
        // Anlass war — sonst würde eine Auslösung aus anderem Grund den nächsten
        // echten Rückfall verschlucken.
        //
        // Gefragt wird dafür die Regel und nicht `$matched`: bei „eine genügt"
        // bricht die Prüfung beim ersten Treffer ab, und der Rückfall stünde
        // dann nicht in der Liste, obwohl er zutrifft. Die Bedingung ist genau
        // `resolvedAt() !== null` und kostet nichts — sie hier zu wiederholen
        // ist billiger, als die Abkürzung aufzugeben.
        $regressionAt = $this->wantsRegression($rule) ? $context->resolvedAt() : null;

        if ($regressionAt !== null && ! in_array(IssueAlertCondition::Regression, $matched, true)) {
            $matched[] = IssueAlertCondition::Regression;
        }

        if (! IssueAlertState::claim($rule->id, $context->issue->id, $rule->frequency_minutes, $now, $regressionAt)) {
            return null;
        }

        $deliveries = $this->notifier->send($rule, $context, $matched);

        return IssueAlertTrigger::query()->create([
            'issue_alert_rule_id' => $rule->id,
            'issue_id' => $context->issue->id,
            'conditions' => array_map(
                static fn (IssueAlertCondition $condition): string => $condition->value,
                $matched,
            ),
            'delivery_count' => $deliveries,
            'occurred_at' => $now,
        ]);
    }

    /**
     * Die vorgezogene Lesung der Begrenzung.
     *
     * Ein Rückfall, den es noch nicht zu melden gab, darf sie durchbrechen: er
     * ist ein neues Ereignis in der Sache und nicht die Wiederholung eines
     * bereits gemeldeten. Ohne diese Ausnahme bliebe ein Fehler, der erledigt
     * und kurz darauf wieder aufgetreten ist, still, weil dieselbe Regel eine
     * halbe Stunde zuvor sein erstes Auftreten gemeldet hatte.
     */
    private function throttled(IssueAlertRule $rule, IssueAlertContext $context, Carbon $now): bool
    {
        $state = IssueAlertState::query()
            ->where('issue_alert_rule_id', $rule->id)
            ->where('issue_id', $context->issue->id)
            ->first();

        if ($state === null || $state->last_notified_at === null) {
            return false;
        }

        $resolvedAt = $context->resolvedAt();

        if ($resolvedAt !== null
            && ($state->regression_at === null || $state->regression_at->lessThan($resolvedAt))
            && $this->wantsRegression($rule)) {
            return false;
        }

        return $state->last_notified_at->greaterThan($now->copy()->subMinutes($rule->frequency_minutes));
    }

    private function wantsRegression(IssueAlertRule $rule): bool
    {
        foreach ($rule->parsedConditions() as $condition) {
            if ($condition->type === IssueAlertCondition::Regression) {
                return true;
            }
        }

        return false;
    }
}
