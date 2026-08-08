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
 * Eine render-blockierende Ressource: ein Skript oder Stylesheet, auf das der
 * Browser wartet, bevor er überhaupt etwas anzeigt.
 *
 * Das Besondere an diesem Muster ist, dass die Zeit **doppelt** zählt. Eine
 * langsame Datei irgendwo mitten im Laden verlängert die Gesamtdauer; eine
 * blockierende verlängert die Zeit, in der der Nutzer auf eine **weiße Seite**
 * sieht. Deshalb ein eigenes Muster und nicht ein Sonderfall der übergroßen
 * Datei: die Dringlichkeit ist eine andere, und die Behebung auch —
 * `defer`, `async` oder das Auslagern ans Ende, nicht das Verkleinern.
 *
 * **Geraten wird hier nichts.** Ob eine Ressource blockiert, sagt der Browser
 * selbst über `resource.render_blocking_status`; die SDKs reichen die Angabe
 * durch. Ein Erkenner, der stattdessen aus Dateiendung und Ladezeitpunkt
 * schlösse, würde bei jedem zweiten `defer`-Skript falschliegen — und ein
 * falscher Alarm bei genau dem Muster, das nach sofortigem Handeln aussieht,
 * ist teurer als eine ausgelassene Meldung.
 */
final class RenderBlockingAsset implements Detector
{
    private const RESOURCE_OPS = ['resource'];

    /**
     * Der Wert, mit dem der Browser eine blockierende Ressource meldet.
     */
    private const BLOCKING = 'blocking';

    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::RenderBlockingAsset;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minDurationUs = $thresholds->durationUs($this->problem());

        $findings = [];

        foreach ($trace->ofOp(self::RESOURCE_OPS) as $span) {
            if ($span->durationUs < $minDurationUs) {
                continue;
            }

            if (! $this->blocks($span)) {
                continue;
            }

            $target = QueryShape::ofUrl($span->description);

            $findings[] = new Finding(
                problem: $this->problem(),
                subject: $target !== '' ? $target : (string) $span->op,
                description: (string) ($span->description ?? $target),
                spanIds: [$span->spanId],
                // Die ganze Ladezeit ist die Zeit vor dem ersten Bild: sie
                // entfällt vollständig, sobald die Ressource nicht mehr
                // blockiert.
                timeLostUs: $span->durationUs,
                evidence: [
                    'duration_us' => $span->durationUs,
                    'resource_op' => $span->op,
                    'encoded_bytes' => $span->intData('http.response_content_length'),
                ],
            );
        }

        return $findings;
    }

    private function blocks(SpanRecord $span): bool
    {
        $status = $span->stringData('resource.render_blocking_status')
            ?? $span->stringData('render_blocking_status');

        return $status !== null && strtolower($status) === self::BLOCKING;
    }
}
