<?php

namespace App\Console\Commands;

use App\Enums\QueueName;
use App\Jobs\ProcessDemoIngest;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DemoIngestCommand extends Command
{
    protected $signature = 'demo:ingest {--fail : Den Job absichtlich scheitern lassen (landet in der Fehlerablage)}';

    protected $description = 'Beispiel-Ingest-Job in die Warteschlange legen (zeigt Hintergrund-Verarbeitung und Broadcast)';

    public function handle(): int
    {
        $reference = Str::upper(Str::random(6));

        ProcessDemoIngest::dispatch($reference, (bool) $this->option('fail'));

        $this->info("Ingest {$reference} eingereiht.");
        $this->line('Worker: php artisan queue:work --queue='.QueueName::priority());

        return self::SUCCESS;
    }
}
