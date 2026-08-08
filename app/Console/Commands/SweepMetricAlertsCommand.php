<?php

namespace App\Console\Commands;

use App\Support\Alerts\MetricAlertSweep;
use Illuminate\Console\Command;

/**
 * Die minütliche Auswertung der Schwellwert-Alarme.
 *
 * Sie ist der Grund, warum ein Alarm überhaupt auslöst: eine Kennzahl meldet
 * sich nicht von selbst, wenn sie schlechter wird. Läuft der Zeitplan der
 * Anwendung nicht (`schedule:work` bzw. ein Minuten-Cron auf `schedule:run`),
 * bleibt es still — und still heißt hier nicht „alles in Ordnung", sondern
 * „niemand sieht nach".
 */
class SweepMetricAlertsCommand extends Command
{
    protected $signature = 'alerts:sweep';

    protected $description = 'Wertet die Schwellwert-Alarme aus und meldet Zustandswechsel';

    public function handle(MetricAlertSweep $sweep): int
    {
        $result = $sweep->run();

        $this->info(sprintf(
            '%d Alarme ausgewertet, %d Zustandswechsel gemeldet, %d fehlgeschlagen.',
            $result['evaluated'],
            $result['transitions'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
