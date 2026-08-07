<?php

namespace App\Support\Ingest\Processing;

use Closure;

/**
 * Ein Glied der Verarbeitungskette.
 *
 * Die Form ist die einer Middleware und nicht die eines einfachen
 * `handle($context)`: nur so kann ein Schritt auch **nach** den folgenden noch
 * etwas tun — Zeit messen, eine Sperre wieder freigeben, ein Ergebnis
 * nachtragen. Ohne `$next` bräuchte jeder solche Fall eine Sonderbehandlung im
 * Rahmen.
 *
 * Zwei Regeln, an die sich ein Schritt halten muss:
 *
 * 1. **Aussortieren heißt `drop()`, nicht `return`.** Wer einfach nicht
 *    weiterreicht, lässt die Meldung ohne Begründung verschwinden; sie gilt
 *    dann als ausgewertet, obwohl nichts passiert ist.
 * 2. **Fehler werden geworfen, nicht verschluckt.** Nur eine Ausnahme führt zur
 *    Wiederholung. Ein Schritt, der bei einem Aussetzer stillschweigend
 *    weitermacht, verwandelt einen behebbaren Fehler in stillen Datenverlust.
 */
interface ProcessingStep
{
    /**
     * @param  Closure(ProcessingContext): void  $next  Der Rest der Kette.
     */
    public function handle(ProcessingContext $context, Closure $next): void;
}
