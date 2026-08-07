<?php

namespace Tests\Support\Ingest;

use App\Enums\DiscardReason;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Ein Schritt, der aussortiert und trotzdem weiterreicht.
 *
 * Absichtlich beides: so lässt sich prüfen, dass der Rahmen den Abbruch
 * durchsetzt und nicht die Gutmütigkeit des einzelnen Schritts — genau das ist
 * der Fehler, den ein später hinzukommender Filter sonst machen würde.
 */
class DroppingStep implements ProcessingStep
{
    public function handle(ProcessingContext $context, Closure $next): void
    {
        $context->drop(DiscardReason::Unreadable, 'testfilter');

        $next($context);
    }
}
