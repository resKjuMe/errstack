<?php

namespace App\Notifications\Drivers;

use App\Models\NotificationChannel;
use App\Notifications\ChannelField;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationMessage;

/**
 * Discord über einen Kanal-Webhook. Discord bestätigt die Annahme mit
 * HTTP 204 ohne Rumpf — dass „kein Inhalt" zurückkommt, ist hier also der
 * Erfolgsfall.
 */
final class DiscordDriver extends HttpChannelDriver
{
    public static function key(): string
    {
        return 'discord';
    }

    public function label(): string
    {
        return 'Discord';
    }

    public function description(): string
    {
        return 'Schickt die Meldung über einen Kanal-Webhook nach Discord.';
    }

    public function fields(): array
    {
        return [
            ChannelField::secret(
                key: 'webhook_url',
                label: 'Webhook-URL',
                hint: 'Kanal › Einstellungen › Integrationen › Webhooks.',
                placeholder: 'https://discord.com/api/webhooks/…',
            ),
        ];
    }

    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'url', 'starts_with:https://discord.com/api/webhooks/'],
        ];
    }

    public function summary(NotificationChannel $channel): string
    {
        return 'Kanal-Webhook';
    }

    public function send(NotificationChannel $channel, NotificationMessage $message): DeliveryResult
    {
        $fields = [];

        foreach ($message->context as $label => $value) {
            $fields[] = ['name' => $label, 'value' => $value, 'inline' => true];
        }

        return $this->post((string) $channel->setting('webhook_url'), [
            'embeds' => [array_filter([
                'title' => $message->title,
                'description' => $message->body,
                'url' => $message->url,
                // Discord erwartet die Farbe als Zahl, nicht als Hex-Text.
                'color' => (int) hexdec($message->level->color()),
                'fields' => $fields,
                'timestamp' => $message->occurredAt?->toIso8601String(),
                'footer' => $message->reference === null ? null : ['text' => $message->reference],
            ], static fn (mixed $value): bool => $value !== null && $value !== [])],
        ]);
    }
}
