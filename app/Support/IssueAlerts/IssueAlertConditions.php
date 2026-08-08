<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueAlertCondition;
use App\Enums\IssueAlertMatch;

/**
 * Beurteilt die Anlässe einer Regel.
 *
 * Die drei ereignisnahen Bedingungen — erstes Auftreten, Rückfall, Aufwachen —
 * kosten nichts: sie stehen schon im {@see IssueAlertContext}. Die drei
 * zählenden fragen die Datenbank, und genau deshalb werden sie **zuletzt**
 * geprüft: bei „eine genügt" ist die Frage oft schon beantwortet, bevor die
 * erste Zählung nötig wird, und bei „alle" ist sie oft schon verneint.
 */
final class IssueAlertConditions
{
    public function __construct(private readonly IssueAlertCounts $counts) {}

    /**
     * Welche Anlässe zutreffen — leer, wenn die Regel nicht greift.
     *
     * Die Liste und nicht nur ein `bool`, weil der Verlauf sie nennt: „warum
     * hat das gefeuert?" ist die erste Rückfrage, und der Regelname beantwortet
     * sie nicht. Eine Regel **ohne** Anlass greift nie — das ist der sichere
     * Ausgang und nicht der bequeme.
     *
     * @param  list<RuleCondition>  $conditions
     * @return list<IssueAlertCondition>
     */
    public function matched(array $conditions, IssueAlertMatch $match, IssueAlertContext $context): array
    {
        if ($conditions === []) {
            return [];
        }

        $matched = [];

        foreach ($this->cheapFirst($conditions) as $condition) {
            if ($this->matches($condition, $context)) {
                $matched[] = $condition->type;

                if ($match === IssueAlertMatch::Any) {
                    return $matched;
                }

                continue;
            }

            if ($match === IssueAlertMatch::All) {
                return [];
            }
        }

        return $matched;
    }

    public function matches(RuleCondition $condition, IssueAlertContext $context): bool
    {
        return match ($condition->type) {
            IssueAlertCondition::NewIssue => $context->isNew,
            IssueAlertCondition::Regression => $context->resolvedAt() !== null,
            IssueAlertCondition::Escalation => $context->escalated,
            IssueAlertCondition::Frequency => $this->counts->events(
                $context->issue,
                $condition->windowMinutes(),
                $context->occurredAt,
            ) > $condition->value,
            IssueAlertCondition::UserFrequency => $this->counts->users(
                $context->issue,
                $condition->windowMinutes(),
                $context->occurredAt,
            ) > $condition->value,
            IssueAlertCondition::PercentChange => $this->percentChanged($condition, $context),
        };
    }

    /**
     * Der Vergleich mit der Vorwoche.
     *
     * Ohne Vergleichswert trifft die Bedingung **nicht** zu. Die Alternative
     * wäre, „keine Vorwoche" als unendlichen Anstieg zu lesen — dann würde
     * jeder neu auftretende Fehler jede Prozentregel reißen, und die Regel
     * meldete genau das, wofür es die Bedingung „neuer Fehler" gibt.
     */
    private function percentChanged(RuleCondition $condition, IssueAlertContext $context): bool
    {
        $change = $this->counts->percentChange(
            $context->issue,
            max(1, $condition->window),
            $context->occurredAt,
        );

        return $change !== null && $change >= $condition->value;
    }

    /**
     * Erst das, was schon dasteht — dann das, was gezählt werden muss.
     *
     * @param  list<RuleCondition>  $conditions
     * @return list<RuleCondition>
     */
    private function cheapFirst(array $conditions): array
    {
        usort(
            $conditions,
            static fn (RuleCondition $a, RuleCondition $b): int => (int) $a->type->hasValue() <=> (int) $b->type->hasValue(),
        );

        return $conditions;
    }
}
