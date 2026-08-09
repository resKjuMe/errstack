<?php

namespace App\Jobs;

use App\Enums\QueueName;
use App\Models\IntegrationWebhookEvent;
use App\Support\Integrations\GitHub\GitHubWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wertet eine eingegangene Meldung des Anbieters aus (X1).
 *
 * Getrennt von der Annahme, weil GitHub zehn Sekunden auf die Antwort wartet
 * und die Zustellung danach als fehlgeschlagen führt. Was die Auswertung tut —
 * Fehler erledigen, Commits nachholen — dauert im Zweifel länger, und ein
 * Zeitablauf drüben hieße: dieselbe Meldung kommt gleich noch einmal, während
 * die erste noch läuft.
 *
 * Die Meldung kommt als **Nummer** herein, wie überall: das Ereignis steht
 * bereits in der Datenbank, und was daran zählt, ist die Zeile, nicht die
 * Momentaufnahme von vorhin.
 */
class ProcessIntegrationWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $eventId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(): void
    {
        $event = IntegrationWebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            // Schon abgehandelt. Der Fall entsteht, wenn ein Auftrag nach einem
            // Zeitablauf ein zweites Mal läuft — die Marke ist die Stelle, an
            // der das auffällt, ohne dass die Auswertung selbst wiederholbar
            // sein müsste.
            return;
        }

        $event->markProcessed(GitHubWebhookProcessor::handle($event));
    }
}
