<?php

namespace App\Console\Commands;

use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use Illuminate\Console\Command;

/**
 * Lässt gescheiterte Meldungen erneut durch die Verarbeitung laufen.
 *
 * Die Gegenstelle zu `queue:retry`, und aus einem Grund nicht dasselbe: dort
 * wird ein gescheiterter **Job** wiederholt, hier eine gescheiterte
 * **Meldung**. Der Unterschied zählt, sobald die Ursache nicht der Lauf war,
 * sondern die Kette — nach einem behobenen Fehler in einem Schritt gibt es
 * keinen Job mehr, den man wiederholen könnte, die Rohdaten liegen aber noch
 * da.
 *
 * Ohne Einschränkung werden alle gescheiterten Meldungen erneut eingereiht.
 */
class IngestRetryCommand extends Command
{
    protected $signature = 'ingest:retry
        {--project= : Nur Meldungen dieses Projekts}
        {--id=* : Nur diese Meldungen (Kennung aus ingest_payloads)}
        {--limit=1000 : Höchstens so viele auf einmal einreihen}';

    protected $description = 'Endgültig gescheiterte Meldungen erneut zur Verarbeitung einreihen';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // Über die Kommandozeile kommt jede Option als Zeichenkette, aus einem
        // Aufruf im Code auch schon mal als Zahl. Beides muss hier gleich
        // wirken — sonst greift die Einschränkung genau dann nicht, wenn man
        // sie nicht von Hand tippt.
        $project = $this->option('project');
        $project = is_scalar($project) && (string) $project !== '' ? (int) $project : null;

        /** @var list<int|string> $ids */
        $ids = (array) $this->option('id');

        $payloads = IngestPayload::query()
            ->failedProcessing()
            ->when($project !== null, fn ($query) => $query->where('project_id', $project))
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($payloads->isEmpty()) {
            $this->components->info('Keine gescheiterten Meldungen gefunden.');

            return self::SUCCESS;
        }

        foreach ($payloads as $payload) {
            // Erst zurückstellen, dann einreihen: ein Arbeiter, der den Job
            // sofort abholt, sieht sonst noch den alten Zustand und hält die
            // Meldung für erledigt.
            $payload->resetProcessing();

            ProcessIngestPayload::dispatch($payload);
        }

        $this->components->info($payloads->count().' Meldung(en) erneut eingereiht.');

        return self::SUCCESS;
    }
}
