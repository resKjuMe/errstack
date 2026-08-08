<?php

namespace App\Support\Performance;

use Carbon\CarbonImmutable;

/**
 * Ein Beispielfall: der eine Aufruf, an dem sich nachsehen lässt, was in einem
 * Perzentil-Bereich tatsächlich passiert ist.
 *
 * **Gewählt wird über das Perzentil und nicht zufällig.** Das ist die Zusage
 * dieser Aufgabe, und sie ist keine Förmlichkeit: ein zufälliger Aufruf ist mit
 * überwältigender Wahrscheinlichkeit ein schneller — genau der, dessentwegen
 * niemand die Seite geöffnet hat. Wer wissen will, warum das p99 bei zwölf
 * Sekunden liegt, braucht einen Aufruf aus diesem Bereich.
 */
final class TransactionSample
{
    /**
     * @param  float  $percentile  Der Bereich, für den dieser Fall steht (0.95 → p95)
     * @param  string|null  $traceHref  Der Weg in die Trace-Ansicht, sofern es
     *                                  sie schon gibt (PF4) — sonst `null`, und
     *                                  die Zeile steht ohne Link da.
     */
    public function __construct(
        public readonly float $percentile,
        public readonly string $eventId,
        public readonly string $traceId,
        public readonly int $durationUs,
        public readonly int $spanCount,
        public readonly ?string $release,
        public readonly CarbonImmutable $startedAt,
        public readonly ?string $traceHref,
    ) {}

    /**
     * @return array{percentile: float, eventId: string, traceId: string, durationUs: int, spanCount: int, release: string|null, startedAt: string, traceHref: string|null}
     */
    public function toArray(): array
    {
        return [
            'percentile' => $this->percentile,
            'eventId' => $this->eventId,
            'traceId' => $this->traceId,
            'durationUs' => $this->durationUs,
            'spanCount' => $this->spanCount,
            'release' => $this->release,
            'startedAt' => $this->startedAt->toIso8601String(),
            'traceHref' => $this->traceHref,
        ];
    }
}
