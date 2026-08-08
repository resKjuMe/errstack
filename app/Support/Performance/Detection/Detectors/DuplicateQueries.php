<?php

namespace App\Support\Performance\Detection\Detectors;

use App\Enums\PerformanceProblem;
use App\Support\Performance\Detection\Detector;
use App\Support\Performance\Detection\Finding;
use App\Support\Performance\Detection\QueryShape;
use App\Support\Performance\Detection\SpanRecord;
use App\Support\Performance\Detection\Thresholds;
use App\Support\Performance\Detection\TraceSnapshot;

/**
 * Doppelte Abfragen: **exakt** dieselbe Abfrage mehrfach in einem Ablauf, samt
 * ihrer Werte.
 *
 * Der Unterschied zu den beiden Serien-Mustern ist die Gewissheit. Dort ist die
 * Behebung eine fachliche Entscheidung — ob sich die Abfragen bündeln lassen,
 * hängt vom Programm ab. Hier nicht: dieselbe Abfrage mit denselben Werten
 * liefert dieselbe Antwort, und jede Wiederholung nach der ersten ist
 * ersatzlos zu streichen.
 *
 * Verglichen wird deshalb der **Klartext** und nicht die Form. Eine Abfrage
 * nach Kunde 1 und eine nach Kunde 2 sind gleichartig, aber nicht doppelt; sie
 * gehören zu den Serien-Mustern, nicht hierher. Genau darin liegt der Wert
 * dieses Erkenners: Er meldet nur Fälle, bei denen es nichts abzuwägen gibt.
 *
 * Die Wiederholungen müssen **nicht** aufeinanderfolgen. Die typische doppelte
 * Abfrage steckt in zwei Programmteilen, die nichts voneinander wissen — am
 * Anfang der Anfrage und noch einmal in der Ansicht.
 */
final class DuplicateQueries implements Detector
{
    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::DuplicateQueries;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minCount = $thresholds->count($this->problem());
        $minTotalUs = $thresholds->durationUs($this->problem(), 'min_total_ms');

        $groups = [];

        foreach ($trace->queries() as $span) {
            $text = trim((string) $span->description);

            if ($text === '') {
                continue;
            }

            $groups[$text][] = $span;
        }

        $findings = [];

        foreach ($groups as $text => $spans) {
            if (count($spans) < $minCount) {
                continue;
            }

            $total = array_sum(array_map(static fn (SpanRecord $span): int => $span->durationUs, $spans));

            if ($total < $minTotalUs) {
                continue;
            }

            $findings[] = new Finding(
                problem: $this->problem(),
                // Der Gegenstand ist die **Form**, nicht der Klartext: sonst
                // bekäme jeder Kunde, für den die Abfrage doppelt läuft, einen
                // eigenen Eintrag — und das eine Problem im Code stünde
                // tausendfach in der Liste.
                subject: $spans[0]->shape !== '' ? $spans[0]->shape : QueryShape::of($text),
                description: (string) $text,
                spanIds: array_map(static fn (SpanRecord $span): string => $span->spanId, $spans),
                // Alles außer der ersten ist ersatzlos verloren.
                timeLostUs: max(0, $total - $spans[0]->durationUs),
                evidence: [
                    'repeats' => count($spans),
                    'total_us' => $total,
                ],
            );
        }

        return $findings;
    }
}
