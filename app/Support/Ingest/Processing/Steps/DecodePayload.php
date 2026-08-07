<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Macht aus den abgelegten Rohdaten einen Feld-Baum, mit dem die folgenden
 * Schritte arbeiten können.
 *
 * Der erste Schritt der Kette und der einzige, der zum Rahmen selbst gehört:
 * ihn bräuchte sonst jeder folgende Schritt für sich, und jeder würde die
 * Zeichenkette erneut zerlegen — bei einer Fehlerflut die teuerste Arbeit im
 * ganzen Durchlauf.
 *
 * Binärelemente gehen unverändert durch. Ein Screenshot ist kein JSON, und ein
 * Anhang ohne Feld-Baum ist kein Mangel, sondern seine Natur — was mit ihm
 * geschieht, entscheidet der Schritt, der Anhänge kennt.
 */
final class DecodePayload implements ProcessingStep
{
    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if ($payload->type->isBinary()) {
            $next($context);

            return;
        }

        $decoded = $payload->decoded();

        if ($decoded === null) {
            // Bei der Annahme war der Rumpf noch lesbar — geprüft wird er dort
            // ja. Ist er es hier nicht mehr, hat ihn etwas zwischen Annahme und
            // Auswertung beschädigt. Wiederholen hilft dagegen nicht; die
            // Meldung wird ausgesondert und gezählt, damit die Lücke später
            // erklärbar bleibt.
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return;
        }

        $context->data = $decoded;

        $next($context);
    }
}
