<?php

namespace App\Support\IssueAlerts;

use App\Enums\EventLevel;
use App\Enums\IssueAlertComparison;
use App\Enums\IssueAlertFilter;
use App\Enums\IssueAlertMatch;
use App\Models\IssueTag;

/**
 * Beurteilt die Einschränkungen einer Regel.
 *
 * Alle Angaben bis auf das Merkmal stehen am Ereignis oder am Eintrag und sind
 * damit ohne weitere Abfrage zu haben. Das Merkmal ist die Ausnahme — es liegt
 * am Ereignis als JSON, und dort steht es genau so, wie es ankam; die
 * vorberechnete Merkmalstabelle ({@see IssueTag}) beantwortet eine andere
 * Frage, nämlich „welche Werte gab es je?" statt „welchen hatte dieser Fall?".
 */
final class IssueAlertFilters
{
    /**
     * @param  list<RuleFilter>  $filters
     */
    public function passes(array $filters, IssueAlertMatch $match, IssueAlertContext $context): bool
    {
        return $match->satisfiedBy(array_map(
            fn (RuleFilter $filter): bool => $this->matches($filter, $context),
            $filters,
        ));
    }

    public function matches(RuleFilter $filter, IssueAlertContext $context): bool
    {
        return match ($filter->type) {
            IssueAlertFilter::Level => $this->matchesLevel($filter, $context->event->level),
            IssueAlertFilter::Age => $filter->comparison->matchesNumber(
                $context->occurredAt->diffInMinutes($context->issue->first_seen, absolute: true),
                (float) $filter->value,
            ),
            IssueAlertFilter::TimesSeen => $filter->comparison->matchesNumber(
                (float) $context->issue->times_seen,
                (float) $filter->value,
            ),
            IssueAlertFilter::Tag => $filter->comparison->matchesText(
                $this->tag($filter->key, $context),
                $filter->value,
            ),
            IssueAlertFilter::Release => $filter->comparison->matchesText($context->event->release, $filter->value),
            IssueAlertFilter::Environment => $filter->comparison->matchesText($context->event->environment, $filter->value),
        };
    }

    /**
     * Der Grad — als **Schwere** verglichen und nicht als Text.
     *
     * „Mindestens error" muss `fatal` einschließen; ein Textvergleich täte
     * genau das nicht, und die Regel bliebe ausgerechnet beim schwersten Fall
     * still.
     */
    private function matchesLevel(RuleFilter $filter, EventLevel $level): bool
    {
        $expected = EventLevel::tryFrom(mb_strtolower($filter->value));

        if ($expected === null) {
            return false;
        }

        if ($filter->comparison === IssueAlertComparison::Equals) {
            return $level === $expected;
        }

        return $filter->comparison->matchesNumber((float) $level->severity(), (float) $expected->severity());
    }

    /**
     * Der Wert eines Merkmals am Ereignis — `null`, wenn es fehlt.
     *
     * Ein fehlendes Merkmal verhält sich damit wie ein leeres: „Browser ist
     * nicht Chrome" trifft auf eine Meldung ohne Browser-Angabe zu, „Browser
     * enthält Chrome" nicht.
     */
    private function tag(?string $key, IssueAlertContext $context): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $tags = $context->event->tags ?? [];

        return $tags[$key] ?? null;
    }
}
