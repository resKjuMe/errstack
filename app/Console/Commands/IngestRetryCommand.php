<?php

namespace App\Console\Commands;

use App\Support\Operations\IngestRetry;
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

    public function handle(IngestRetry $retry): int
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

        // Das Einreihen selbst steht in App\Support\Operations\IngestRetry —
        // die Betriebsansicht (O5) hat dieselbe Schaltfläche, und zwei
        // Umsetzungen desselben Ablaufs gehen genau an der Reihenfolge
        // auseinander.
        $count = $retry->queueFailed($project, $ids, $limit);

        if ($count === 0) {
            $this->components->info('Keine gescheiterten Meldungen gefunden.');

            return self::SUCCESS;
        }

        $this->components->info($count.' Meldung(en) erneut eingereiht.');

        return self::SUCCESS;
    }
}
