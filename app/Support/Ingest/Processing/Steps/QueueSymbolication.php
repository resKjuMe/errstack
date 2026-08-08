<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Jobs\SymbolicateEvent;
use App\Models\Event;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\SourceMaps\Symbolicator;
use Closure;

/**
 * Reiht die Rückübersetzung des Stacktraces ein.
 *
 * Wie die Leistungserkennung ({@see ScanPerformance}) ist der Schritt eine
 * Beauftragung und keine Arbeit: was er kostet, ist ein Eintrag in einer
 * Warteschlange, und was er verhindert, ist das Einlesen einer mehrere Megabyte
 * großen Quellkarte in derselben Ausführung wie die Aufnahme.
 *
 * **Gefragt wird vorher, ob es etwas zu übersetzen gibt.** Eine Anwendung, die
 * ihre Fehler aus einem PHP-Backend meldet, hat keinen minimierten Stacktrace —
 * ein Auftrag je Meldung wäre eine Warteschlange voller Nichts, und zwar genau in
 * dem Projekt, das am meisten meldet.
 *
 * Er steht am **Ende** der Kette, hinter dem Speichern der Meldung
 * ({@see NormalizeEvent}) und aus demselben Grund wie das Erfassen der Version:
 * beauftragt werden soll nur, was auch bleibt. Ein Auftrag für eine Meldung, die
 * ein Filter danach verwirft, würde ins Leere laufen — die Kette bricht davor
 * allerdings ohnehin ab, sodass er hier nie ankommt.
 */
final class QueueSymbolication implements ProcessingStep
{
    public function handle(ProcessingContext $context, Closure $next): void
    {
        $event = $context->get(NormalizeEvent::RESULT.'_record');

        if ($event instanceof Event && Symbolicator::isApplicable($event)) {
            SymbolicateEvent::dispatch($event);
        }

        $next($context);
    }
}
