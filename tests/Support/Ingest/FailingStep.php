<?php

namespace Tests\Support\Ingest;

use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;
use RuntimeException;

/**
 * Ein Schritt, der die ersten Aufrufe scheitern lässt und danach durchlässt —
 * die Form einer Störung, gegen die Wiederholung überhaupt hilft.
 */
class FailingStep implements ProcessingStep
{
    /** Wie oft der Schritt noch scheitern soll. */
    public static int $remainingFailures = 0;

    public static function failTimes(int $times): void
    {
        self::$remainingFailures = $times;
    }

    public function handle(ProcessingContext $context, Closure $next): void
    {
        if (self::$remainingFailures > 0) {
            self::$remainingFailures--;

            throw new RuntimeException('Schritt vorübergehend gescheitert.');
        }

        $next($context);
    }
}
