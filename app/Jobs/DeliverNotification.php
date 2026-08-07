<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Enums\QueueName;
use App\Models\NotificationDelivery;
use App\Notifications\ChannelRegistry;
use App\Notifications\DeliveryResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Stellt genau einen Protokolleintrag zu. Das ist der einzige Ort, an dem
 * überhaupt nach außen gefunkt wird — im Web-Request passiert nichts davon.
 *
 * Ein Fehlschlag wird erst protokolliert und dann als Ausnahme
 * weitergereicht: die Warteschlange sorgt dann für den nächsten Versuch. Erst
 * wenn alle Versuche verbraucht sind, gilt die Zustellung als gescheitert
 * (`failed()`).
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public NotificationDelivery $delivery)
    {
        $this->onQueue(QueueName::Notifications->value);
    }

    /**
     * Versuche, bevor die Zustellung endgültig als fehlgeschlagen gilt.
     */
    public function tries(): int
    {
        return (int) config('notifications.tries', 5);
    }

    /**
     * Wartezeiten zwischen den Versuchen in Sekunden. Sie wachsen, weil die
     * häufigste Ursache — das Ziel ist gerade nicht erreichbar — Zeit braucht
     * und nicht Wiederholungen.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('notifications.backoff', [10, 60, 300, 900]);

        return $backoff;
    }

    public function handle(ChannelRegistry $registry): void
    {
        // Erneut geladen, weil zwischen Einreihen und Abarbeiten Minuten
        // liegen können: der Kanal kann inzwischen gelöscht oder abgeschaltet
        // worden sein.
        $delivery = $this->delivery->fresh(['channel.organization']);

        if ($delivery === null || $delivery->channel === null) {
            return;
        }

        if ($delivery->status === DeliveryStatus::Sent) {
            return;
        }

        if (! $delivery->channel->is_active) {
            $delivery->markFailed(__('notifications.deliveries.channel_off'));

            return;
        }

        $result = $this->deliver($registry, $delivery);

        $delivery->recordAttempt($result);

        if (! $result->ok) {
            // Die Ausnahme ist hier das Signal an die Warteschlange, es später
            // erneut zu versuchen — der Grund steht bereits im Protokoll.
            throw new RuntimeException($result->error ?? 'Zustellung fehlgeschlagen.');
        }
    }

    /**
     * Endgültig gescheitert: alle Versuche sind verbraucht (oder der Kanal
     * wirft etwas, womit niemand gerechnet hat).
     */
    public function failed(Throwable $exception): void
    {
        $this->delivery->fresh()?->markFailed($exception->getMessage());
    }

    /**
     * Ein Kanal darf mit einer Ausnahme scheitern (etwa der Mail-Versand).
     * Auch die gehört ins Protokoll, statt als roher Stapel-Auszug in den Logs
     * zu landen.
     */
    private function deliver(ChannelRegistry $registry, NotificationDelivery $delivery): DeliveryResult
    {
        try {
            return $registry->driver($delivery->channel->type)
                ->send($delivery->channel, $delivery->message());
        } catch (Throwable $exception) {
            return DeliveryResult::failure($exception->getMessage());
        }
    }
}
