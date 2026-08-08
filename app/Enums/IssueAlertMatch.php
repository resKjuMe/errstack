<?php

namespace App\Enums;

/**
 * Ob alle Bedingungen (bzw. Filter) einer Regel zutreffen müssen oder eine
 * genügt.
 *
 * Eine leere Liste gilt als erfüllt — bei `All` folgt das aus der Logik, bei
 * `Any` ausdrücklich nicht, und trotzdem ist es hier gewollt: eine Regel ganz
 * ohne Filter soll nicht nie greifen, sondern immer.
 */
enum IssueAlertMatch: string
{
    case All = 'all';

    case Any = 'any';

    /**
     * @param  list<bool>  $results
     */
    public function satisfiedBy(array $results): bool
    {
        if ($results === []) {
            return true;
        }

        return match ($this) {
            self::All => ! in_array(false, $results, true),
            self::Any => in_array(true, $results, true),
        };
    }

    public function label(): string
    {
        return __('enums.issue_alert_match.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $match): array => [
            'value' => $match->value,
            'label' => $match->label(),
        ], self::cases());
    }
}
