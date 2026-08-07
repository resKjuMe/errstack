<?php

namespace App\Support;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ChannelField;
use App\Notifications\ChannelRegistry;
use App\Notifications\Contracts\ChannelDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Benachrichtigungs-Seite: eingerichtete Kanäle, die
 * Kanal-Auswahl samt ihrer Felder und das Zustellprotokoll.
 *
 * Zugangsdaten gehen nie an den Browser zurück. Felder, die als Geheimnis
 * gekennzeichnet sind, werden ausgelassen — die Oberfläche zeigt sie leer und
 * behandelt „leer gelassen" als „unverändert".
 */
final class NotificationData
{
    /**
     * @return array<string, mixed>
     */
    public static function index(Organization $organization, User $viewer, ChannelRegistry $registry): array
    {
        $manage = Gate::forUser($viewer)->allows('manageNotifications', $organization);

        $channels = $organization->notificationChannels()
            ->orderBy('name')
            ->get()
            ->each(fn (NotificationChannel $channel) => $channel->setRelation('organization', $organization));

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'permissions' => [
                'manage' => $manage,
            ],
            'channels' => $channels
                ->map(fn (NotificationChannel $channel): array => self::channel($channel, $registry))
                ->all(),
            'catalog' => array_map(
                static fn (ChannelDriver $driver): array => [
                    'type' => $driver::key(),
                    'label' => $driver->label(),
                    'description' => $driver->description(),
                    'fields' => array_map(
                        static fn (ChannelField $field): array => $field->toArray(),
                        $driver->fields(),
                    ),
                ],
                array_values($registry->all()),
            ),
            'deliveries' => self::deliveries($organization),
            // Der Aufbau der Webhook-Unterschrift gehört zur Einrichtung: ohne
            // ihn weiß der Empfänger nicht, wie er die Zustellung prüft.
            'webhookDocs' => 'docs/webhooks.md',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function channel(NotificationChannel $channel, ChannelRegistry $registry): array
    {
        $known = $registry->has($channel->type);
        $driver = $known ? $registry->driver($channel->type) : null;

        return [
            'id' => $channel->id,
            'type' => $channel->type,
            // Ein Kanal, dessen Treiber nicht mehr eingetragen ist, soll
            // sichtbar bleiben — sonst verschwindet er lautlos aus der Liste.
            'typeLabel' => $driver?->label() ?? $channel->type,
            'name' => $channel->name,
            'isActive' => $channel->is_active,
            'summary' => $driver?->summary($channel) ?? 'Unbekannter Kanal',
            'known' => $known,
            'fields' => $driver === null ? [] : array_map(
                static fn (ChannelField $field): array => $field->toArray(),
                $driver->fields(),
            ),
            // Nur die offenen Werte; Zugangsdaten bleiben serverseitig.
            'values' => $driver === null ? [] : self::values($channel, $driver),
            'href' => route('notifications.update', $channel),
            'testHref' => route('notifications.test', $channel),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(NotificationChannel $channel, ChannelDriver $driver): array
    {
        $values = [];

        foreach ($driver->fields() as $field) {
            if ($field->secret) {
                continue;
            }

            $value = $channel->setting($field->key);
            $values[$field->key] = $field->type === 'list' && is_array($value)
                ? implode("\n", $value)
                : $value;
        }

        return $values;
    }

    /**
     * Zustellprotokoll der Organisation, jüngste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    private static function deliveries(Organization $organization): array
    {
        return NotificationDelivery::query()
            ->with('channel')
            ->whereHas('channel', fn (Builder $query) => $query->where('organization_id', $organization->id))
            ->latest()
            ->limit((int) config('notifications.log_limit', 50))
            ->get()
            ->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'channel' => $delivery->channel->name,
                'subject' => $delivery->subject,
                'status' => $delivery->status->value,
                'statusLabel' => $delivery->status->label(),
                'attempts' => $delivery->attempts,
                'responseCode' => $delivery->response_code,
                'error' => $delivery->error,
                'isTest' => $delivery->is_test,
                'createdAt' => $delivery->created_at->format('d.m.Y H:i'),
                'deliveredAt' => $delivery->delivered_at?->format('d.m.Y H:i'),
                'retryHref' => route('deliveries.retry', $delivery),
            ])
            ->all();
    }
}
