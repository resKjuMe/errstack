<?php

namespace App\Notifications\Drivers;

use App\Models\NotificationChannel;
use App\Notifications\ChannelField;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationMessage;

/**
 * Allgemeiner Webhook: die Meldung als JSON an eine beliebige eigene Adresse,
 * unterschrieben mit einem gemeinsamen Geheimnis.
 *
 * Die Unterschrift steht in `X-Errstack-Signature` und ist ein HMAC-SHA256
 * über „Zeitstempel.Rumpf" — der Zeitstempel gehört mit hinein, damit ein
 * abgefangener Aufruf nicht beliebig oft wiederholt werden kann. Der genaue
 * Aufbau samt Beispiel steht in `docs/webhooks.md`; die Werte dort und die
 * Kopfzeilen hier müssen zusammenpassen.
 */
final class WebhookDriver extends HttpChannelDriver
{
    /** Fassung des Unterschrift-Verfahrens. Ändert es sich, steigt die Zahl. */
    public const SIGNATURE_VERSION = 'v1';

    public static function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return __('channels.webhook.label');
    }

    public function description(): string
    {
        return __('channels.webhook.description');
    }

    public function fields(): array
    {
        return [
            new ChannelField(
                key: 'url',
                label: __('channels.webhook.url'),
                type: 'url',
                placeholder: 'https://example.com/errstack',
            ),
            ChannelField::secret(
                key: 'secret',
                label: __('channels.webhook.secret'),
                hint: __('channels.webhook.secret_hint'),
            ),
        ];
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'starts_with:https://'],
            'secret' => ['required', 'string', 'min:16', 'max:255'],
        ];
    }

    public function summary(NotificationChannel $channel): string
    {
        $url = (string) $channel->setting('url');

        // Nur Rechnername statt vollständiger URL: der Pfad kann selbst schon
        // ein Geheimnis sein.
        return parse_url($url, PHP_URL_HOST) ?: __('channels.webhook.summary');
    }

    public function send(NotificationChannel $channel, NotificationMessage $message): DeliveryResult
    {
        $body = $this->encode([
            'event' => 'notification',
            'organization' => [
                'slug' => $channel->organization->slug,
                'name' => $channel->organization->name,
            ],
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
            ],
            'delivery' => [
                'id' => $message->deliveryId,
                'test' => $message->reference !== null && str_starts_with($message->reference, 'TEST-'),
            ],
            'message' => $message->toArray(),
        ]);

        $timestamp = now()->getTimestamp();

        return $this->postBody((string) $channel->setting('url'), $body, array_filter([
            'X-Errstack-Event' => 'notification',
            'X-Errstack-Delivery' => $message->deliveryId === null ? null : (string) $message->deliveryId,
            'X-Errstack-Timestamp' => (string) $timestamp,
            'X-Errstack-Signature' => self::signature(
                (string) $channel->setting('secret'),
                $timestamp,
                $body,
            ),
        ], static fn (?string $value): bool => $value !== null));
    }

    /**
     * Unterschrift einer Zustellung: `v1=<hex>` über „Zeitstempel.Rumpf".
     * Öffentlich und statisch, damit Tests und Dokumentation dieselbe Rechnung
     * benutzen wie der Versand.
     */
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        $digest = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return self::SIGNATURE_VERSION.'='.$digest;
    }
}
