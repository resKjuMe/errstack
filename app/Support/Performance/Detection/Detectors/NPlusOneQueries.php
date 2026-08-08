<?php

namespace App\Support\Performance\Detection\Detectors;

use App\Enums\PerformanceProblem;
use App\Support\Performance\Detection\Detector;
use App\Support\Performance\Detection\Finding;
use App\Support\Performance\Detection\SpanRecord;
use App\Support\Performance\Detection\Thresholds;
use App\Support\Performance\Detection\TraceSnapshot;

/**
 * N+1-Abfragen: eine Abfrage holt eine Liste, danach wird für jeden Eintrag
 * derselben Liste einzeln nachgefragt.
 *
 * **Woran das Muster wirklich zu erkennen ist** — und woran nicht: nicht an der
 * bloßen Wiederholung. Eine Abfrageform, die zwanzig Mal vorkommt, kann ein
 * gebündelter Import sein. Was das N+1 ausmacht, ist die **auslösende**
 * Abfrage: eine andere Abfrage, die vor der Serie steht und deren Ergebnis die
 * Serie erzeugt hat. Sie ist zugleich der Ansatzpunkt für die Behebung — dort
 * gehört das Vorabladen hin, nicht in die Schleife.
 *
 * Deshalb verlangt dieser Erkenner drei Dinge zugleich: eine wiederholte
 * Abfrageform, eine andersartige Abfrage davor, und **denselben umschließenden
 * Schritt** für die ganze Serie. Das letzte hält die Fehlmeldung ab, bei der
 * zwei getrennte Programmteile zufällig dieselbe Abfrage benutzen.
 *
 * Die verlorene Zeit ist die Summe der Wiederholungen **ohne die erste**: eine
 * Abfrage wäre auch nach der Behebung nötig, die übrigen n−1 sind das, was ein
 * Join oder ein Vorabladen einspart.
 */
final class NPlusOneQueries implements Detector
{
    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::NPlusOneQueries;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minCount = $thresholds->count($this->problem());
        $minTotalUs = $thresholds->durationUs($this->problem(), 'min_total_ms');

        $findings = [];

        foreach ($this->groupsByParentAndShape($trace->queries()) as $group) {
            if (count($group['spans']) < $minCount) {
                continue;
            }

            $total = array_sum(array_map(
                static fn (SpanRecord $span): int => $span->durationUs,
                $group['spans'],
            ));

            if ($total < $minTotalUs) {
                continue;
            }

            $source = $this->sourceQuery($trace, $group['spans'][0], $group['shape']);

            if ($source === null) {
                continue;
            }

            $repeats = count($group['spans']);

            // Ohne die erste: die bleibt auch nach der Behebung.
            $timeLost = $total - $group['spans'][0]->durationUs;

            $findings[] = new Finding(
                problem: $this->problem(),
                subject: $group['shape'],
                description: (string) $group['spans'][0]->description,
                spanIds: array_map(static fn (SpanRecord $span): string => $span->spanId, $group['spans']),
                timeLostUs: max(0, $timeLost),
                evidence: [
                    'repeats' => $repeats,
                    'total_us' => $total,
                    'source_span_id' => $source->spanId,
                    'source_description' => $source->description,
                ],
            );
        }

        return $findings;
    }

    /**
     * Die Wiederholungen, gebündelt nach umschließendem Schritt und Abfrageform.
     *
     * @param  list<SpanRecord>  $queries
     * @return list<array{shape: string, parent: string, spans: list<SpanRecord>}>
     */
    private function groupsByParentAndShape(array $queries): array
    {
        $groups = [];

        foreach ($queries as $span) {
            if ($span->shape === '') {
                continue;
            }

            $key = ($span->parentSpanId ?? '').'|'.$span->shape;

            $groups[$key] ??= ['shape' => $span->shape, 'parent' => $span->parentSpanId ?? '', 'spans' => []];
            $groups[$key]['spans'][] = $span;
        }

        return array_values($groups);
    }

    /**
     * Die auslösende Abfrage: eine **andere** Abfrageform, die vor der Serie
     * abgeschlossen war und unter demselben Elternteil steht.
     *
     * „Abgeschlossen" und nicht bloß „früher begonnen": aus einem Ergebnis kann
     * nur eine Schleife werden, wenn das Ergebnis vorliegt. Eine parallel
     * laufende Abfrage ist keine Quelle, sondern eine zweite Baustelle.
     *
     * Gesucht wird die **späteste** passende — bei mehreren vorangegangenen
     * Abfragen ist die unmittelbar davor die wahrscheinliche Quelle.
     */
    private function sourceQuery(TraceSnapshot $trace, SpanRecord $first, string $shape): ?SpanRecord
    {
        $candidate = null;

        foreach ($trace->queries() as $span) {
            if ($span->shape === $shape || $span->shape === '') {
                continue;
            }

            if ($span->parentSpanId !== $first->parentSpanId) {
                continue;
            }

            if (! $first->follows($span)) {
                continue;
            }

            if ($candidate === null || $span->endsUs() > $candidate->endsUs()) {
                $candidate = $span;
            }
        }

        return $candidate;
    }
}
