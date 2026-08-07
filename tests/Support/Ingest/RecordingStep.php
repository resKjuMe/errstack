<?php

namespace Tests\Support\Ingest;

use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Ein Schritt, der nichts tut außer sich zu merken, dass er gelaufen ist.
 *
 * Damit lässt sich das Versprechen der Kette prüfen, um das es in I3 geht: ein
 * neuer Schritt wird angehängt und läuft mit, ohne dass an einem bestehenden
 * etwas geändert wurde.
 */
class RecordingStep implements ProcessingStep
{
    /**
     * Kennungen der Meldungen, die durch diesen Schritt gelaufen sind.
     *
     * @var list<int>
     */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }

    public function handle(ProcessingContext $context, Closure $next): void
    {
        self::$seen[] = $context->payload->id;

        $next($context);
    }
}
