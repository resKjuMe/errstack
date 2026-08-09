<?php

namespace App\Console\Commands;

use App\Support\Performance\Trends\TrendScan;
use Illuminate\Console\Command;

/**
 * Die regelmäßige Suche nach Trendbrüchen.
 *
 * Sie ist der Grund, warum eine schleichende Verschlechterung überhaupt
 * auffällt. Läuft der Zeitplan der Anwendung nicht (`schedule:work` bzw. ein
 * Minuten-Cron auf `schedule:run`), bleibt es still — und still heißt hier nicht
 * „alles unverändert", sondern „niemand rechnet nach".
 *
 * Von Hand aufgerufen ist er außerdem der Weg, das Ergebnis nach einem Umbau der
 * Schwellen sofort zu sehen, statt bis zur nächsten vollen Stunde zu warten.
 */
class SweepTransactionTrendsCommand extends Command
{
    protected $signature = 'performance:trends';

    protected $description = 'Sucht Brüche in den Antwortzeiten und meldet Verschlechterungen';

    public function handle(TrendScan $scan): int
    {
        $result = $scan->run();

        $this->info(sprintf(
            '%d Projekte, %d Transaktionen geprüft, %d Brüche festgestellt, %d gemeldet, %d fehlgeschlagen.',
            $result['projects'],
            $result['transactions'],
            $result['found'],
            $result['notified'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
