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
 * Ein langsamer Aufruf an einen fremden Dienst.
 *
 * Das einfachste Muster von allen — und deshalb wertvoll: ein Aufruf, der eine
 * Sekunde dauert, ist an sich schon die Antwort auf „warum ist die Seite
 * langsam", ohne dass man ein Muster erkennen müsste. Was der Erkenner
 * hinzufügt, ist die **Gruppierung**: derselbe Aufruf, tausendfach langsam,
 * wird ein Eintrag mit einer Häufigkeit statt tausend Zeilen im Protokoll.
 *
 * **Die verlorene Zeit ist die Zeit über der Schwelle**, nicht die ganze Dauer.
 * Ein fremder Dienst braucht immer etwas; was zu holen ist, ist der Teil
 * darüber. Andernfalls stünde in der Liste eine Zahl, die niemand einsparen
 * kann, und die Rangfolge der Einträge wäre keine Rangfolge des Nutzens mehr.
 *
 * Gruppiert wird nach **Adresse ohne Werte**: `/api/kunden/4711` und
 * `/api/kunden/4712` sind derselbe Aufruf ({@see QueryShape::ofUrl()}). Wer
 * darauf verzichtet, bekommt je Kunde einen eigenen Eintrag und hat die Flut,
 * die die Gruppierung verhindern soll.
 */
final class SlowHttpCall implements Detector
{
    /**
     * Die Vorgänge, unter denen die SDKs einen ausgehenden Aufruf melden.
     */
    private const HTTP_OPS = ['http', 'grpc', 'soap'];

    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::SlowHttpCall;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minDurationUs = $thresholds->durationUs($this->problem());

        $findings = [];

        foreach ($trace->ofOp(self::HTTP_OPS) as $span) {
            if ($span->durationUs < $minDurationUs) {
                continue;
            }

            $target = $this->target($span);

            $findings[] = new Finding(
                problem: $this->problem(),
                subject: $target,
                description: (string) ($span->description ?? $target),
                spanIds: [$span->spanId],
                timeLostUs: $span->durationUs - $minDurationUs,
                evidence: [
                    'duration_us' => $span->durationUs,
                    'threshold_us' => $minDurationUs,
                    'method' => $span->stringData('http.request.method') ?? $span->stringData('http.method'),
                    'status' => $span->intData('http.response.status_code') ?? $span->intData('http.status_code'),
                ],
            );
        }

        return $findings;
    }

    /**
     * Wohin der Aufruf ging.
     *
     * Die Beschreibung eines HTTP-Schritts ist bei den meisten SDKs
     * `GET https://…` — Verb und Adresse in einem. Die Adresse steht daneben
     * auch in den Zusatzangaben, aber nicht bei jedem SDK und nicht unter
     * demselben Schlüssel; deshalb beides, mit der ausdrücklichen Angabe
     * zuerst.
     */
    private function target(SpanRecord $span): string
    {
        $url = $span->stringData('url.full')
            ?? $span->stringData('http.url')
            ?? $span->stringData('url');

        if ($url === null) {
            $description = trim((string) $span->description);

            // `GET https://…` — das Verb abtrennen, damit derselbe Aufruf mit
            // und ohne mitgeschickte Adresse denselben Gegenstand ergibt.
            if (preg_match('/^[A-Z]{3,7}\s+(\S+)$/', $description, $matches) === 1) {
                $url = $matches[1];
            } else {
                $url = $description;
            }
        }

        $shape = QueryShape::ofUrl($url);

        return $shape !== '' ? $shape : (string) $span->op;
    }
}
