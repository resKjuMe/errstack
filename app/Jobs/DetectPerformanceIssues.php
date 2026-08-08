<?php

namespace App\Jobs;

use App\Enums\QueueName;
use App\Models\Transaction;
use App\Support\Performance\Detection\PerformanceScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sieht einen gespeicherten Ablauf nach Leistungsmustern durch.
 *
 * **Warum ein eigener Auftrag und nicht ein Schritt der Verarbeitung:** die
 * Zusage lautet, dass die Erkennung auf gespeicherten Abläufen läuft und nie im
 * Aufnahme-Request. Ein Schritt in der Kette wäre zwar auch schon hinter der
 * Warteschlange — aber in **derselben** Ausführung wie die Aufnahme, und damit
 * teilte er sich deren Zeitgrenze, deren Wiederholungen und deren
 * Warteschlange. Eine Anwendung mit fünfhundert Schritten je Aufruf würde die
 * Aufnahme ihrer eigenen Fehlermeldungen ausbremsen, weil davor noch acht
 * Erkenner über Zehntausende Vergleiche liefen.
 *
 * Getrennt kann die Erkennung langsam sein, ohne dass es jemanden stört: sie
 * hat ihre eigene Warteschlange ({@see QueueName::Performance}), ihre eigenen
 * Arbeiter und ihre eigene Rückstau-Anzeige.
 *
 * Der Auftrag ist **wiederholbar**. Er darf mehrfach zugestellt werden, und ein
 * zweiter Durchlauf ändert nichts: der eindeutige Index am Fund lässt denselben
 * Vorfall kein zweites Mal entstehen.
 */
class DetectPerformanceIssues implements ShouldQueue
{
    use Queueable;

    /**
     * Drei Versuche, nicht fünf wie bei der Aufnahme.
     *
     * Eine verlorene Auswertung ist eine Zeile weniger in einer Liste; eine
     * verlorene Meldung ist ein Fehler, von dem niemand erfährt. Die
     * Hartnäckigkeit darf sich unterscheiden.
     */
    public int $tries = 3;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Transaction $transaction,
    ) {
        $this->onQueue(QueueName::Performance->value);
    }

    /**
     * Ein Ablauf wird nicht zweimal gleichzeitig durchgesehen.
     *
     * Nötig ist die Sperre nicht — der eindeutige Index am Fund fängt das
     * Doppelte ohnehin ab. Sie erspart nur die Arbeit: zwei Arbeiter, die
     * dieselben fünfhundert Schritte vergleichen, um am Ende beide dasselbe
     * „gibt es schon" zu bekommen.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('performance-scan:'.$this->transaction->id))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(): void
    {
        $transaction = $this->transaction;

        // Schon angesehen. Der Vermerk steht am Ende der Auswertung, ist also
        // ein Beleg für einen **abgeschlossenen** Durchlauf und nicht für einen
        // begonnenen ({@see PerformanceScanner::scan()}).
        if ($transaction->scanned_at !== null) {
            return;
        }

        PerformanceScanner::fromConfig()->scan($transaction);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Leistungserkennung für eine Transaktion gescheitert.', [
            'transaktion' => $this->transaction->id,
            'projekt' => $this->transaction->project_id,
            'grund' => $exception?->getMessage(),
        ]);
    }
}
