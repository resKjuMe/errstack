<?php

namespace App\Support\Profiling;

/**
 * Eine Zeile der Funktionsliste: was eine Funktion insgesamt gekostet hat.
 *
 * Im Flamegraph ist dieselbe Funktion so oft zu sehen, wie es Wege zu ihr gibt.
 * Hier steht sie einmal — und genau das macht den Unterschied sichtbar zwischen
 * „einmal richtig teuer" und „zweihundertmal ein bisschen".
 */
final class FunctionTotal
{
    public function __construct(
        public readonly ProfileFrame $frame,
        public readonly int $selfNs,
        public readonly int $totalNs,
        public readonly int $selfSamples,
    ) {}

    /**
     * Die Form für die Oberfläche.
     *
     * Die Zeiten gehen als **Mikrosekunden** hinaus, nicht als Nanosekunden:
     * gerechnet wird intern in Nanosekunden, weil die SDKs so messen, aber eine
     * Zahl in Nanosekunden überschreitet bei längeren Aufrufen den Bereich, in
     * dem JavaScript ganze Zahlen genau darstellt. Ein Wert, der im Browser
     * lautlos gerundet wird, ist in einer Auswertung, die Anteile ausrechnet,
     * ein Fehler.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'function' => $this->frame->function,
            'module' => $this->frame->module,
            'file' => $this->frame->file,
            'line' => $this->frame->line,
            'inApp' => $this->frame->inApp,
            'selfUs' => intdiv($this->selfNs, 1000),
            'totalUs' => intdiv($this->totalNs, 1000),
            'samples' => $this->selfSamples,
        ];
    }
}
