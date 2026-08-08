<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueAlertCondition;

/**
 * Ein Anlass einer Regel, wie er in der Regel steht — geprüft und ausgepackt.
 *
 * Die Auswertung sieht diese Klasse und nie das rohe JSON. Das ist der einzige
 * Ort, an dem „was in der Spalte steht" in „was gemeint ist" übersetzt wird;
 * eine zweite Stelle wäre die, an der ein neuer Bedingungstyp vergessen wird.
 */
final readonly class RuleCondition
{
    public function __construct(
        public IssueAlertCondition $type,
        public int $value = 0,
        public int $window = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $type = IssueAlertCondition::tryFrom(is_string($raw['type'] ?? null) ? $raw['type'] : '');

        if ($type === null) {
            return null;
        }

        return new self(
            type: $type,
            value: (int) ($raw['value'] ?? 0),
            window: (int) ($raw['window'] ?? 0),
        );
    }

    /**
     * Das Zeitfenster in Minuten — unabhängig davon, in welcher Einheit es
     * angegeben wurde.
     */
    public function windowMinutes(): int
    {
        return $this->type->windowUnit()?->toMinutes($this->window) ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type->value];

        if ($this->type->hasValue()) {
            $data['value'] = $this->value;
        }

        if ($this->type->windowUnit() !== null) {
            $data['window'] = $this->window;
        }

        return $data;
    }
}
