<?php

namespace App\Console\Commands;

use App\Support\Attachments\AttachmentSweep;
use App\Support\Formats;
use Illuminate\Console\Command;

/**
 * Der Durchlauf, der abgelaufene Anhänge wegräumt (M5).
 *
 * Er ist die Gegenseite zur Zusage auf der Fehlerseite („verfällt am …"): ohne ihn
 * wäre die Frist eine Behauptung, und die Anhänge wären der Teil des Bestands, der
 * am schnellsten wächst — eine Meldung sind wenige Kilobyte, ein Speicherabbild
 * zwanzig Megabyte.
 *
 * Läuft der Zeitplan der Anwendung nicht (`schedule:work` bzw. ein Minuten-Cron
 * auf `schedule:run`), bleibt alles liegen. Das fällt nirgends auf, bis die Platte
 * voll ist — deshalb steht es hier.
 */
class PruneAttachmentsCommand extends Command
{
    protected $signature = 'attachments:prune';

    protected $description = 'Löscht Anhänge, deren Aufbewahrungsfrist abgelaufen ist';

    public function handle(AttachmentSweep $sweep): int
    {
        $result = $sweep->run();

        // Zwei Zahlen und nicht eine: gelöscht wird die Zeile, freigegeben wird
        // die Datei — und beides fällt auseinander, sobald zwei Anhänge auf
        // denselben Inhalt zeigen oder das Laufwerk ein Löschen verweigert
        // ({@see App\Support\Attachments\AttachmentStore::delete()}).
        $this->info(sprintf(
            '%d Projekte betrachtet, %d Anhänge gelöscht, %s freigegeben.',
            $result['projects'],
            $result['deleted'],
            Formats::bytes($result['bytes']),
        ));

        return self::SUCCESS;
    }
}
