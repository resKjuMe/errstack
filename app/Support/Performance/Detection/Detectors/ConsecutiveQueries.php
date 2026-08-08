<?php

namespace App\Support\Performance\Detection\Detectors;

use App\Enums\PerformanceProblem;
use App\Support\Performance\Detection\Detector;
use App\Support\Performance\Detection\Finding;
use App\Support\Performance\Detection\PerformanceScanner;
use App\Support\Performance\Detection\SpanRecord;
use App\Support\Performance\Detection\Thresholds;
use App\Support\Performance\Detection\TraceSnapshot;

/**
 * Aufeinanderfolgende gleichartige Abfragen: dieselbe Abfrageform mehrfach
 * hintereinander, jede wartet auf die vorige.
 *
 * Der Unterschied zum N+1 ({@see NPlusOneQueries}) ist die fehlende auslösende
 * Abfrage. Was bleibt, ist trotzdem verlorene Zeit: fünf Abfragen zu je zwanzig
 * Millisekunden kosten nacheinander hundert Millisekunden und gebündelt
 * zwanzig. Die Behebung ist eine andere — nicht Vorabladen, sondern Bündeln
 * oder Nebenläufigkeit —, deshalb ein eigenes Muster und kein Sonderfall.
 *
 * **„Nacheinander" ist die eigentliche Bedingung**, nicht „mehrfach". Zwei
 * Abfragen, die sich überlappen, laufen bereits parallel und kosten zusammen
 * nur die längere; dort ist nichts zu holen. Geprüft wird deshalb Schritt für
 * Schritt, ob der nächste erst nach dem Ende des vorigen begonnen hat
 * ({@see SpanRecord::follows()}).
 *
 * Eine N+1-Serie erfüllt diese Bedingung ebenfalls — sie ist ja auch
 * nacheinander. Dass sie hier nicht ein zweites Mal in der Liste landet, regelt
 * nicht dieser Erkenner, sondern die Reihenfolge im Ablauf: wer als Erster
 * Schritte beansprucht, behält sie
 * ({@see PerformanceScanner}). So bleibt
 * jeder Erkenner bei seiner einen Frage, statt die der anderen mitzuprüfen.
 */
final class ConsecutiveQueries implements Detector
{
    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::ConsecutiveQueries;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minCount = $thresholds->count($this->problem());
        $minTotalUs = $thresholds->durationUs($this->problem(), 'min_total_ms');

        $findings = [];

        foreach ($this->runs($trace->queries()) as $run) {
            if (count($run) < $minCount) {
                continue;
            }

            $total = array_sum(array_map(static fn (SpanRecord $span): int => $span->durationUs, $run));

            if ($total < $minTotalUs) {
                continue;
            }

            // Gebündelt bliebe die längste der Abfragen übrig — alles darüber
            // ist Wartezeit, die nur entsteht, weil nacheinander gefragt wird.
            $longest = max(array_map(static fn (SpanRecord $span): int => $span->durationUs, $run));

            $findings[] = new Finding(
                problem: $this->problem(),
                subject: $run[0]->shape,
                description: (string) $run[0]->description,
                spanIds: array_map(static fn (SpanRecord $span): string => $span->spanId, $run),
                timeLostUs: max(0, $total - $longest),
                evidence: [
                    'repeats' => count($run),
                    'total_us' => $total,
                    'longest_us' => $longest,
                ],
            );
        }

        return $findings;
    }

    /**
     * Ununterbrochene Ketten derselben Abfrageform.
     *
     * Eine Kette endet, sobald eine andere Form dazwischenkommt **oder** die
     * nächste Abfrage schon lief, während die vorige noch offen war. Der zweite
     * Fall ist der wichtigere: er trennt „wartet aufeinander" von „läuft
     * nebeneinander", und nur das Erste ist ein Problem.
     *
     * @param  list<SpanRecord>  $queries
     * @return list<list<SpanRecord>>
     */
    private function runs(array $queries): array
    {
        // Nach Anfangszeit, nicht nach Meldereihenfolge: die Reihenfolge, in
        // der ein SDK Schritte anhängt, ist die ihres **Endes** — verschachtelte
        // Abfragen kommen von innen nach außen. Für „was folgte worauf" ist das
        // die falsche Richtung.
        usort($queries, static fn (SpanRecord $a, SpanRecord $b): int => $a->startedUs <=> $b->startedUs);

        $runs = [];
        $current = [];

        foreach ($queries as $span) {
            if ($span->shape === '') {
                continue;
            }

            $previous = $current === [] ? null : $current[count($current) - 1];

            if ($previous !== null && $previous->shape === $span->shape && $span->follows($previous)) {
                $current[] = $span;

                continue;
            }

            if (count($current) > 1) {
                $runs[] = $current;
            }

            $current = [$span];
        }

        if (count($current) > 1) {
            $runs[] = $current;
        }

        return $runs;
    }
}
