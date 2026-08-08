<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueAlertAction;

/**
 * Eine Aktion einer Regel, wie sie in der Regel steht — geprüft und ausgepackt.
 */
final readonly class RuleAction
{
    public function __construct(
        public IssueAlertAction $type,
        /** Ein bestimmter Kanal — `null` heißt „alle aktiven der Organisation". */
        public ?int $channelId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $type = IssueAlertAction::tryFrom(is_string($raw['type'] ?? null) ? $raw['type'] : '');

        if ($type === null) {
            return null;
        }

        $channelId = $raw['channel_id'] ?? null;

        return new self(
            type: $type,
            channelId: is_numeric($channelId) ? (int) $channelId : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type->value];

        if ($this->type === IssueAlertAction::Channel) {
            $data['channel_id'] = $this->channelId;
        }

        return $data;
    }
}
