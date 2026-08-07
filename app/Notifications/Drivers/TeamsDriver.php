<?php

namespace App\Notifications\Drivers;

use App\Models\NotificationChannel;
use App\Notifications\ChannelField;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationMessage;

/**
 * Microsoft Teams über eine eingehende Webhook-URL (Connector bzw. Workflow).
 * Teams nimmt eine „MessageCard" entgegen — ein eigenes Format, das sowohl der
 * klassische Connector als auch der Workflow-Empfang versteht.
 */
final class TeamsDriver extends HttpChannelDriver
{
    public static function key(): string
    {
        return 'teams';
    }

    public function label(): string
    {
        return __('channels.teams.label');
    }

    public function description(): string
    {
        return __('channels.teams.description');
    }

    public function fields(): array
    {
        return [
            ChannelField::secret(
                key: 'webhook_url',
                label: __('channels.teams.webhook_url'),
                hint: __('channels.teams.webhook_url_hint'),
                placeholder: 'https://….webhook.office.com/…',
            ),
        ];
    }

    public function rules(): array
    {
        return [
            'webhook_url' => ['required', 'url', 'starts_with:https://'],
        ];
    }

    public function summary(NotificationChannel $channel): string
    {
        return __('channels.teams.summary');
    }

    public function send(NotificationChannel $channel, NotificationMessage $message): DeliveryResult
    {
        $facts = [];

        foreach ($message->context as $label => $value) {
            $facts[] = ['name' => $label, 'value' => $value];
        }

        return $this->post((string) $channel->setting('webhook_url'), array_filter([
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            // `summary` ist Pflicht: Teams zeigt sie in der Benachrichtigung,
            // und ohne sie weist der Empfang die Karte ab.
            'summary' => $message->title,
            'themeColor' => $message->level->color(),
            'title' => $message->title,
            'text' => $message->body,
            'sections' => $facts === [] ? null : [['facts' => $facts]],
            'potentialAction' => $message->url === null ? null : [[
                '@type' => 'OpenUri',
                'name' => 'In Errstack öffnen',
                'targets' => [['os' => 'default', 'uri' => $message->url]],
            ]],
        ], static fn (mixed $value): bool => $value !== null));
    }
}
