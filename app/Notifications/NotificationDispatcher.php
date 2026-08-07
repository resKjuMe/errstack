<?php

namespace App\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Jobs\DeliverNotification;
use App\Jobs\DeliverPersonalNotification;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
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
    public function __construct(private readonly NotificationPreferences $preferences) {}

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

    /**
     * Meldung an eine einzelne Person — anders als `send()` nicht an die
     * Verteiler der Organisation, sondern an ihren eigenen Posteingang.
     *
     * Was tatsächlich rausgeht, entscheiden ihre persönlichen Einstellungen
     * (App\Notifications\NotificationPreferences). Zurück kommen die Wege, die
     * erlaubt sind: die E-Mail reiht diese Methode selbst ein, das Postfach in
     * der Anwendung liest den Rest.
     *
     * @return list<NotificationTransport>
     */
    public function sendToUser(
        User $user,
        NotificationMessage $message,
        NotificationEventType $event,
        ?Project $project = null,
        ?Organization $organization = null,
    ): array {
        $transports = $this->preferences->transportsFor($user, $event, $project, $organization);

        if (in_array(NotificationTransport::Mail, $transports, true)) {
            DeliverPersonalNotification::dispatch($user, $message, $event, $project, $organization);
        }

        return $transports;
    }

    /**
     * Dieselbe Meldung an mehrere Personen. Jede wird einzeln gefragt — genau
     * darin liegt der Zweck der persönlichen Einstellungen.
     *
     * @param  iterable<User>  $users
     * @return array<int, list<NotificationTransport>>
     */
    public function sendToUsers(
        iterable $users,
        NotificationMessage $message,
        NotificationEventType $event,
        ?Project $project = null,
        ?Organization $organization = null,
    ): array {
        $result = [];

        foreach ($users as $user) {
            $result[$user->id] = $this->sendToUser($user, $message, $event, $project, $organization);
        }

        return $result;
    }

    private function queue(NotificationDelivery $delivery): void
    {
        DeliverNotification::dispatch($delivery);
    }
}
