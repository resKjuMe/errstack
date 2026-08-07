<?php

namespace Tests\Support\Ingest;

use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Ein Schritt, der sich merkt, was er von seinen Vorgängern vorgefunden hat.
 *
 * Damit lässt sich prüfen, was ein Schritt den folgenden **zusagt** — nicht
 * bloß, was er in die Datenbank schreibt. Für I5 und I6 ist genau das die
 * Schnittstelle: sie holen das Ergebnis unter einem Namen ab, und dass es dort
 * liegt, ist eine Zusage und keine innere Angelegenheit.
 */
class CapturingStep implements ProcessingStep
{
    public static ?ProcessingContext $last = null;

    public static function reset(): void
    {
        self::$last = null;
    }

    public function handle(ProcessingContext $context, Closure $next): void
    {
        self::$last = $context;

        $next($context);
    }
}
