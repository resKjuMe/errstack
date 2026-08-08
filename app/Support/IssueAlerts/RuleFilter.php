<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueAlertComparison;
use App\Enums\IssueAlertFilter;

/**
 * Eine Einschränkung einer Regel, wie sie in der Regel steht — geprüft und
 * ausgepackt. Gegenstück zu {@see RuleCondition}.
 */
final readonly class RuleFilter
{
    public function __construct(
        public IssueAlertFilter $type,
        public IssueAlertComparison $comparison,
        public string $value = '',
        public ?string $key = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $type = IssueAlertFilter::tryFrom(is_string($raw['type'] ?? null) ? $raw['type'] : '');
        $comparison = IssueAlertComparison::tryFrom(is_string($raw['comparison'] ?? null) ? $raw['comparison'] : '');

        // Ein Vergleich, den dieser Filter gar nicht kennt, ist kein Sonderfall
        // der Auswertung, sondern ein unbrauchbarer Eintrag: er käme aus einer
        // Regel, die an der Eingabeprüfung vorbei entstanden ist.
        if ($type === null || $comparison === null || ! in_array($comparison, $type->comparisons(), true)) {
            return null;
        }

        return new self(
            type: $type,
            comparison: $comparison,
            value: is_scalar($raw['value'] ?? null) ? (string) $raw['value'] : '',
            key: is_string($raw['key'] ?? null) ? $raw['key'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type->value,
            'comparison' => $this->comparison->value,
            'value' => $this->value,
        ];

        if ($this->type->hasKey()) {
            $data['key'] = $this->key;
        }

        return $data;
    }
}
