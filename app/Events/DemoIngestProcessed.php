<?php

namespace App\Events;

use App\Enums\QueueName;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Beispiel-Broadcast: meldet einer offenen Ansicht, dass ein Ingest-Job fertig
 * ist — ohne Neuladen. Ersetzt später der echte Ingest-Broadcast.
 *
 * Der Kanal ist bewusst öffentlich: Rechte und private Kanäle kommen mit der
 * Anmeldung (F3) und den Organisationen (F4).
 */
class DemoIngestProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $reference,
        public string $message,
        public string $processedAt,
    ) {}

    /**
     * Das Versenden läuft selbst über die Warteschlange — auf `notifications`,
     * damit es die Ingest-Verarbeitung nicht ausbremst.
     */
    public function broadcastQueue(): string
    {
        return QueueName::Notifications->value;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('demo');
    }

    public function broadcastAs(): string
    {
        return 'demo.ingest.processed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'reference' => $this->reference,
            'message' => $this->message,
            'processedAt' => $this->processedAt,
        ];
    }
}
