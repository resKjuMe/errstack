<?php

namespace App\Notifications\Drivers;

use App\Models\NotificationChannel;
use App\Notifications\ChannelField;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationMessage;

/**
 * Slack über einen eingehenden Webhook („Incoming Webhook"). Der Ziel-Kanal
 * steckt in der URL — Slack vergibt sie je Kanal —, deshalb genügt hier die
 * URL und ein Anzeigename für die eigene Liste.
 */
final class SlackDriver extends HttpChannelDriver
{
    public static function key(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return __('channels.slack.label');
    }

    public function description(): string
    {
        return __('channels.slack.description');
    }

    public function fields(): array
    {
        return [
            ChannelField::secret(
                key: 'webhook_url',
                label: __('channels.slack.webhook_url'),
                hint: __('channels.slack.webhook_url_hint'),
                placeholder: 'https://hooks.slack.com/services/…',
            ),
        ];
    }

    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'url', 'starts_with:https://hooks.slack.com/'],
        ];
    }

    public function summary(NotificationChannel $channel): string
    {
        return __('channels.slack.summary');
    }

    public function send(NotificationChannel $channel, NotificationMessage $message): DeliveryResult
    {
        $fields = [];

        foreach ($message->context as $label => $value) {
            $fields[] = ['title' => $label, 'value' => $value, 'short' => true];
        }

        return $this->post((string) $channel->setting('webhook_url'), [
            // `text` ist zugleich der Text der Mitteilung auf dem Sperrbildschirm.
            'text' => $message->title,
            'attachments' => [array_filter([
                'color' => '#'.$message->level->color(),
                'title' => $message->title,
                'title_link' => $message->url,
                'text' => $message->body,
                'fields' => $fields,
                'footer' => $message->reference,
                'ts' => $message->occurredAt?->getTimestamp(),
            ], static fn (mixed $value): bool => $value !== null && $value !== [])],
        ]);
    }
}
