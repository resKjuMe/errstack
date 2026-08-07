<?php

namespace App\Console\Commands;

use App\Support\Crons\CronMonitorSweep;
use Illuminate\Console\Command;

/**
 * Die minütliche Prüfung der überwachten Cronjobs.
 *
 * Sie ist der Grund, warum die Überwachung überhaupt funktioniert: alles andere
 * geschieht, weil ein Job sich meldet — hier geschieht etwas, **weil er es
 * nicht tut**. Läuft der Zeitplan der Anwendung nicht (`schedule:work` bzw. ein
 * Minuten-Cron auf `schedule:run`), fällt kein Ausfall mehr auf, und die
 * Überwachung meldet still nichts mehr.
 */
class SweepCronMonitorsCommand extends Command
{
    protected $signature = 'crons:sweep';

    protected $description = 'Stellt verpasste und zu lange laufende Cronjob-Ausführungen fest';

    public function handle(CronMonitorSweep $sweep): int
    {
        $result = $sweep->run();

        $this->info(sprintf(
            '%d verpasste und %d zu lange laufende Ausführungen festgestellt.',
            $result['missed'],
            $result['timeout'],
        ));

        return self::SUCCESS;
    }
}
