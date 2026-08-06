<?php

namespace App\Notifications;

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverNotification;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Die eine Anlaufstelle für alles, was benachrichtigen will. Der Alert-Kern
 * (A2/A3) kennt nur diese Klasse und `NotificationMessage` — welche Kanäle es
 * gibt und wie sie zustellen, bleibt hinter dem Verzeichnis verborgen.
 *
 * Zugestellt wird ausschließlich in der Warteschlange: hier entsteht nur der
 * Protokolleintrag, der Rest passiert im Job. Ein langsamer Slack-Server darf
 * niemals einen Web-Request aufhalten.
 */
final class NotificationDispatcher
{
    /**
     * Meldung an alle aktiven Kanäle einer Organisation.
     *
     * @return Collection<int, NotificationDelivery>
     */
    public function send(Organization $organization, NotificationMessage $message): Collection
    {
        return $organization->notificationChannels()
            ->where('is_active', true)
            ->get()
            ->map(fn (NotificationChannel $channel): NotificationDelivery => $this->sendTo($channel, $message));
    }

    /**
     * Meldung an genau einen Kanal — der Weg der Testnachricht und jedes
     * einzelnen Wiederholungsversuchs.
     */
    public function sendTo(NotificationChannel $channel, NotificationMessage $message, bool $isTest = false): NotificationDelivery
    {
        /** @var NotificationDelivery $delivery */
        $delivery = $channel->deliveries()->create([
            'subject' => $message->title,
            'payload' => $message->toArray(),
            'status' => DeliveryStatus::Pending,
            'is_test' => $isTest,
        ]);

        $this->queue($delivery);

        return $delivery;
    }

    /**
     * Reiht einen vorhandenen Protokolleintrag (erneut) ein. Die Nutzlast
     * liegt im Eintrag, ein Wiederholungsversuch schickt deshalb wortgleich
     * dieselbe Nachricht.
     */
    public function retry(NotificationDelivery $delivery): void
    {
        $delivery->markPending();

        $this->queue($delivery);
    }

    private function queue(NotificationDelivery $delivery): void
    {
        DeliverNotification::dispatch($delivery);
    }
}
