<?php

namespace App\Support\Ingest\Processing;

use Closure;
use InvalidArgumentException;

/**
 * Die Verarbeitungskette: die Schritte in ihrer festgelegten Reihenfolge.
 *
 * Welche Schritte das sind, steht in `config/ingest.php` und nicht hier. Ein
 * neuer Schritt ist damit eine neue Klasse und eine neue Zeile — keine
 * Änderung an einem bestehenden Schritt und keine an diesem Rahmen. Genau das
 * ist der Zweck: die Kette wächst über mehrere Aufgaben hinweg (I4 bis I9), und
 * jede davon soll die vorherigen unberührt lassen können.
 *
 * Die Reihenfolge ist nicht beliebig, sondern das eigentliche Wissen dieser
 * Klasse — begründet ist sie in der Konfiguration.
 */
final class ProcessingPipeline
{
    /**
     * @param  list<ProcessingStep>  $steps
     */
    public function __construct(
        private readonly array $steps,
    ) {}

    /**
     * Baut die Kette aus der Konfiguration.
     *
     * Die Klassen werden über den Dienstbehälter geholt, damit ein Schritt
     * seine Abhängigkeiten im Konstruktor verlangen kann — die späteren
     * brauchen Projekteinstellungen, Regelwerke und Zähler.
     */
    public static function fromConfig(): self
    {
        $configured = config('ingest.processing.steps');

        if (! is_array($configured)) {
            throw new InvalidArgumentException('ingest.processing.steps muss eine Liste von Klassennamen sein.');
        }

        $steps = [];

        foreach ($configured as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(
                    'Unbekannter Verarbeitungsschritt in ingest.processing.steps: '
                    .(is_string($class) ? $class : get_debug_type($class))
                );
            }

            $step = app($class);

            if (! $step instanceof ProcessingStep) {
                throw new InvalidArgumentException($class.' ist kein '.ProcessingStep::class.'.');
            }

            $steps[] = $step;
        }

        return new self($steps);
    }

    /**
     * Lässt eine Meldung durch alle Schritte laufen.
     *
     * Sortiert ein Schritt aus, endet die Kette dort — auch wenn er brav
     * weitergereicht hat. Das nimmt jedem Schritt die Pflicht, den Abbruch
     * selbst durchzusetzen, und verhindert den Fehler, der sonst reihum
     * gemacht würde: nach einem `drop()` weiterzuarbeiten, als wäre nichts
     * gewesen.
     */
    public function process(ProcessingContext $context): void
    {
        $chain = array_reduce(
            array_reverse($this->steps),
            static fn (Closure $next, ProcessingStep $step): Closure => static function (ProcessingContext $context) use ($step, $next): void {
                if ($context->isDropped()) {
                    return;
                }

                $step->handle($context, $next);
            },
            static function (ProcessingContext $context): void {
                // Ende der Kette. Der Rahmen hält das Ergebnis fest, nicht der
                // letzte Schritt — sonst hinge das Festhalten daran, welcher
                // Schritt gerade der letzte ist.
            },
        );

        $chain($context);
    }
}
