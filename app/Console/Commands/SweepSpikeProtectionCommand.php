<?php

namespace App\Console\Commands;

use App\Support\Ingest\Spikes\SpikeSweep;
use Illuminate\Console\Command;

/**
 * Der minütliche Durchlauf des Ausschlag-Schutzes (A7).
 *
 * Er schreibt die Aufnahmemenge der abgeschlossenen Minute fest, verbucht, was
 * eine laufende Drosselung verworfen hat, bildet den Vergleichswert neu und
 * beendet eine Drosselung, wenn sich die Menge beruhigt hat.
 *
 * Läuft der Zeitplan nicht, wächst kein Verlauf — und ohne Verlauf drosselt der
 * Schutz nicht. Das ist die harmlose Richtung; unangenehm ist der andere Fall:
 * eine bereits laufende Drosselung endet dann nicht von selbst. Dafür gibt es
 * den Knopf zum Aufheben von Hand auf der Projektseite.
 */
class SweepSpikeProtectionCommand extends Command
{
    protected $signature = 'spikes:sweep';

    protected $description = 'Schreibt die Aufnahmemenge je Minute fest und steuert den Ausschlag-Schutz';

    public function handle(SpikeSweep $sweep): int
    {
        $result = $sweep->run();

        $this->info(sprintf(
            '%d Projekte geprüft, %d davon gedrosselt, %d Drosselungen beendet, %d Ereignisse verworfen.',
            $result['projects'],
            $result['throttling'],
            $result['ended'],
            $result['discarded'],
        ));

        return self::SUCCESS;
    }
}
