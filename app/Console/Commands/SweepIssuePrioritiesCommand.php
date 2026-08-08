<?php

namespace App\Console\Commands;

use App\Support\Issues\IssuePrioritySweep;
use Illuminate\Console\Command;

/**
 * Der Durchlauf, der die Wichtigkeit der Fehler fortschreibt und Eskalationen
 * feststellt (S11).
 *
 * Er ist der Grund, warum die Fehlerliste überhaupt eine Rangfolge hat: eine
 * Wichtigkeit, die beim Anzeigen gerechnet wird, ist an das Hinsehen gebunden —
 * und ein stummgeschalteter Fehler, der aus dem Ruder läuft, meldet sich nicht
 * von selbst. Läuft der Zeitplan der Anwendung nicht (`schedule:work` bzw. ein
 * Minuten-Cron auf `schedule:run`), bleibt jede Einordnung auf dem Stand von
 * damals stehen — und das sieht aus wie „passt schon".
 */
class SweepIssuePrioritiesCommand extends Command
{
    protected $signature = 'issues:prioritize';

    protected $description = 'Ermittelt die Wichtigkeit der Fehler und erkennt eskalierte Stummschaltungen';

    public function handle(IssuePrioritySweep $sweep): int
    {
        $result = $sweep->run();

        $this->info(sprintf(
            '%d Fehler betrachtet, %d neu eingeordnet, %d eskaliert, %d fehlgeschlagen.',
            $result['examined'],
            $result['changed'],
            $result['escalated'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
