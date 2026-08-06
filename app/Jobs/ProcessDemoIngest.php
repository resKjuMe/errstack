<?php

namespace App\Jobs;

use App\Enums\QueueName;
use App\Events\DemoIngestProcessed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Beispiel-Job der Ingest-Warteschlange: steht stellvertretend für die spätere
 * Verarbeitung eingehender Fehlermeldungen und meldet das Ergebnis per
 * Broadcast an offene Ansichten.
 *
 * Mit `$shouldFail` lässt sich ein Fehlschlag erzwingen — der Job wandert nach
 * den Versuchen in die Fehlerablage (`failed_jobs`) und ist von dort mit
 * `php artisan queue:retry` erneut startbar.
 */
class ProcessDemoIngest implements ShouldQueue
{
    use Queueable;

    /** Versuche, bevor der Job in der Fehlerablage landet. */
    public int $tries = 3;

    /** Wartezeit in Sekunden zwischen den Versuchen. */
    public int $backoff = 5;

    public function __construct(
        public string $reference,
        public bool $shouldFail = false,
    ) {
        $this->onQueue(QueueName::Ingest->value);
    }

    public function handle(): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException("Beispiel-Ingest {$this->reference} ist absichtlich fehlgeschlagen.");
        }

        DemoIngestProcessed::dispatch(
            $this->reference,
            "Ingest {$this->reference} im Hintergrund verarbeitet.",
            now()->toDateTimeString(),
        );
    }
}
