<?php

namespace App\Console\Commands;

use App\Enums\ProcessingState;
use App\Support\Ingest\Processing\ProcessingMetrics;
use Illuminate\Console\Command;

/**
 * Die Auskunft, die im Betrieb als Erstes gebraucht wird: kommt die
 * Verarbeitung mit?
 *
 * Bewusst ein Kommando und keine Ansicht — es soll auch dann eine Antwort
 * geben, wenn die Oberfläche gerade nicht erreichbar ist, und es soll sich in
 * eine Überwachung einhängen lassen.
 */
class IngestStatusCommand extends Command
{
    protected $signature = 'ingest:status';

    protected $description = 'Rückstand, Verarbeitungsdauern und Fehlschläge der Ingest-Verarbeitung anzeigen';

    public function handle(ProcessingMetrics $metrics): int
    {
        $durations = $metrics->durations();
        $queued = $metrics->queued();
        $oldest = $metrics->oldestPendingSeconds();

        $this->components->twoColumnDetail('Rückstand (wartende Meldungen)', (string) $metrics->backlog());
        $this->components->twoColumnDetail('Ältester Rückstand', $oldest === null ? '—' : $oldest.' s');
        $this->components->twoColumnDetail('Jobs in der Warteschlange', $queued === null ? 'unbekannt' : (string) $queued);
        $this->components->twoColumnDetail('Endgültig gescheitert', (string) $metrics->failed());

        $this->newLine();
        $this->components->twoColumnDetail('Gemessene Durchläufe', (string) $durations['count']);
        $this->components->twoColumnDetail('Dauer Ø', self::ms($durations['avg_ms']));
        $this->components->twoColumnDetail('Dauer 95. Perzentil', self::ms($durations['p95_ms']));
        $this->components->twoColumnDetail('Dauer längster', self::ms($durations['max_ms']));

        $this->newLine();

        foreach ($metrics->states() as $state => $total) {
            $this->components->twoColumnDetail(ProcessingState::from($state)->label(), (string) $total);
        }

        return self::SUCCESS;
    }

    private static function ms(?int $value): string
    {
        return $value === null ? '—' : $value.' ms';
    }
}
