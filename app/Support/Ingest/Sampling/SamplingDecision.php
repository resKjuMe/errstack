<?php

namespace App\Support\Ingest\Sampling;

use App\Models\SamplingRule;
use App\Models\Transaction;

/**
 * Das Ergebnis der Stichprobe für eine Transaktion: behalten oder nicht — und
 * mit welchen Quoten sie später hochzurechnen ist.
 *
 * Die Quoten stehen hier und nicht erst am Datensatz, weil sie auch dann
 * gebraucht werden, wenn nichts gespeichert wird: an einer verworfenen Messung
 * ist die Quote die Begründung. Der Schritt legt die Entscheidung unter
 * {@see CONTEXT_KEY} im Verarbeitungskontext ab; die Ablage der Messung holt sie
 * dort ab und schreibt beide Quoten an die Zeile
 * ({@see Transaction::sampleWeight()}).
 */
final class SamplingDecision
{
    /**
     * Der Name, unter dem die Entscheidung im Verarbeitungskontext liegt.
     *
     * Er steht an der Entscheidung und nicht am Schritt, der sie fällt: sonst
     * müsste der Schritt, der die Messung ablegt, den Schritt kennen, der die
     * Stichprobe zieht — und die Kette soll gerade ohne solche Bekanntschaften
     * wachsen können.
     */
    public const CONTEXT_KEY = 'sampling';

    private function __construct(
        public readonly bool $keep,
        /** Was das SDK behalten hat, `null` wenn es nichts dazu gesagt hat. */
        public readonly ?float $clientRate,
        /** Was wir behalten haben — bei einer verworfenen Messung die Quote der Regel. */
        public readonly float $serverRate,
        /** Die Regel, die entschieden hat — `null`, wenn keine zutraf. */
        public readonly ?SamplingRule $rule,
        /**
         * Behalten, weil die Mindestquote es verlangte, und nicht, weil der Wurf
         * es hergab. Der Unterschied ist für die Hochrechnung wesentlich: eine
         * solche Messung steht für sich selbst und nicht für viele
         * ({@see $serverRate} ist dann 1).
         */
        public readonly bool $guaranteed,
    ) {}

    /**
     * Behalten, weil der Wurf in die Quote fiel. Die Messung steht damit für
     * `1 / Quote` Aufrufe.
     */
    public static function keep(?float $clientRate, float $serverRate, ?SamplingRule $rule): self
    {
        return new self(true, $clientRate, $serverRate, $rule, false);
    }

    /**
     * Behalten, weil dieser Vorgang im laufenden Fenster noch unter der
     * Mindestquote liegt.
     *
     * Die Quote ist hier 1 und nicht die der Regel — die Messung wurde mit
     * Sicherheit behalten, nicht mit einer Wahrscheinlichkeit. Sie mit der Quote
     * der Regel hochzurechnen hieße, aus einem garantierten Aufruf hundert zu
     * machen: gerade bei den seltenen Vorgängen, um deren Sichtbarkeit es hier
     * geht, wäre der ausgewiesene Durchsatz dann frei erfunden.
     */
    public static function guaranteed(?float $clientRate, ?SamplingRule $rule): self
    {
        return new self(true, $clientRate, 1.0, $rule, true);
    }

    /**
     * Nicht behalten. Die Messung wird gezählt und nicht gespeichert.
     */
    public static function discard(?float $clientRate, float $serverRate, ?SamplingRule $rule): self
    {
        return new self(false, $clientRate, $serverRate, $rule, false);
    }
}
