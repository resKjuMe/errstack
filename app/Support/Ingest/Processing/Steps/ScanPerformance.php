<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Jobs\DetectPerformanceIssues;
use App\Models\Transaction;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Reiht den gespeicherten Ablauf zur Leistungserkennung ein.
 *
 * Der ganze Schritt ist eine Zeile, und das ist der Punkt: die Aufnahme
 * **beauftragt** die Erkennung, sie führt sie nicht aus. Was sie kostet, ist
 * ein Eintrag in einer Warteschlange; was sie einbringt, ist die Zusage, dass
 * kein Vergleich über Zehntausende Schritte in derselben Ausführung wie die
 * Aufnahme stattfindet ({@see DetectPerformanceIssues}).
 *
 * Er steht **hinter** {@see RecordTransaction}, denn der Auftrag zeigt auf eine
 * gespeicherte Zeile. Eine Transaktion, die verworfen wurde — durch eine
 * Stichprobe, durch einen Filter —, kommt hier gar nicht an: die Kette bricht
 * vorher ab.
 */
final class ScanPerformance implements ProcessingStep
{
    public function handle(ProcessingContext $context, Closure $next): void
    {
        $transaction = $context->get(RecordTransaction::RESULT);

        if ($transaction instanceof Transaction) {
            DetectPerformanceIssues::dispatch($transaction);
        }

        $next($context);
    }
}
