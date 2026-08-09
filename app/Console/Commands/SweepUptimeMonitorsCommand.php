<?php

namespace App\Console\Commands;

use App\Support\Uptime\UptimeSweep;
use Illuminate\Console\Command;

/**
 * Der minütliche Anstoß der Erreichbarkeits-Prüfungen.
 *
 * Er ist der Grund, warum ein Totalausfall überhaupt auffallen kann: alles
 * andere in dieser Anwendung geschieht, weil sich jemand meldet — hier
 * geschieht etwas, **weil niemand mehr etwas melden kann**. Läuft der Zeitplan
 * der Anwendung nicht (`schedule:work` bzw. ein Minuten-Cron auf
 * `schedule:run`), wird nichts mehr geprüft, und die Überwachung schweigt still.
 *
 * Der Befehl prüft nicht selbst, er reiht nur ein — die Prüfungen laufen
 * ausschließlich in der Warteschlange.
 */
class SweepUptimeMonitorsCommand extends Command
{
    protected $signature = 'uptime:sweep';

    protected $description = 'Reiht die fälligen Erreichbarkeits-Prüfungen in die Warteschlange ein';

    public function handle(UptimeSweep $sweep): int
    {
        $queued = $sweep->run();

        $this->info(sprintf('%d Erreichbarkeits-Prüfungen eingereiht.', $queued));

        return self::SUCCESS;
    }
}
