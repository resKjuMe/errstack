<?php

namespace App\Console\Commands;

use App\Support\Digests\DigestFlusher;
use Illuminate\Console\Command;

/**
 * Der minütliche Blick in die Wartekörbe der Bündelung (A6).
 *
 * Er ist der einzige Weg, auf dem eine zurückgehaltene Meldung je wieder
 * herauskommt. Läuft der Zeitplan der Anwendung nicht, bleibt es still — und
 * anders als bei den Alarmen ist das hier besonders heimtückisch: die
 * Meldungen sind bereits angenommen worden, sie liegen nur da. Wer eine Mail
 * vermisst, sucht dann an der falschen Stelle.
 */
class FlushNotificationDigestsCommand extends Command
{
    protected $signature = 'notifications:flush-digests';

    protected $description = 'Verschickt fällige Sammelnachrichten der Bündelung';

    public function handle(DigestFlusher $flusher): int
    {
        $flushed = $flusher->flush();

        $this->info(sprintf('%d Sammelnachrichten auf den Weg gebracht.', $flushed));

        return self::SUCCESS;
    }
}
