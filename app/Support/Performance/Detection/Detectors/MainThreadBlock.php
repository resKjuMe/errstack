<?php

namespace App\Support\Performance\Detection\Detectors;

use App\Enums\PerformanceProblem;
use App\Support\Performance\Detection\Detector;
use App\Support\Performance\Detection\Finding;
use App\Support\Performance\Detection\SpanRecord;
use App\Support\Performance\Detection\Thresholds;
use App\Support\Performance\Detection\TraceSnapshot;

/**
 * Eine Hauptthread-Blockade: ein Stück Arbeit, das den Browser so lange
 * beschäftigt, dass er in der Zeit auf keine Eingabe reagiert.
 *
 * Das einzige Muster, das nichts mit Warten zu tun hat. Alle anderen messen
 * Zeit, in der die Anwendung auf etwas wartet — auf die Datenbank, auf einen
 * Dienst, auf eine Datei. Hier wartet niemand: der Rechner arbeitet, und genau
 * deshalb reagiert die Oberfläche nicht. Ein Klick in dieser Zeit passiert
 * scheinbar nicht.
 *
 * **Die verlorene Zeit ist die Zeit über 50 Millisekunden**, nicht die ganze
 * Dauer der Aufgabe. Die 50 sind keine Einstellung, sondern die Definition:
 * bis dahin gilt eine Aufgabe als kurz genug, um eine Eingabe rechtzeitig
 * durchzulassen. Was darüber liegt, ist die Zeit, in der die Oberfläche steht —
 * und nur die ist der Schaden. Die einstellbare Schwelle sagt etwas anderes: ab
 * wann es sich zu melden lohnt.
 */
final class MainThreadBlock implements Detector
{
    /**
     * Die Vorgänge, unter denen die SDKs eine lange Aufgabe melden.
     */
    private const BLOCKING_OPS = ['ui.long-task', 'ui.long_task', 'ui.longtask'];

    /**
     * Ab wann eine Aufgabe als lang gilt — die Grenze aus der
     * Long-Tasks-Definition des Browsers, in Mikrosekunden.
     */
    private const LONG_TASK_US = 50_000;

    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::MainThreadBlock;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minDurationUs = max(self::LONG_TASK_US, $thresholds->durationUs($this->problem()));

        $findings = [];

        foreach ($trace->ofOp(self::BLOCKING_OPS) as $span) {
            if ($span->durationUs < $minDurationUs) {
                continue;
            }

            $findings[] = new Finding(
                problem: $this->problem(),
                subject: $this->subject($span, $trace),
                description: (string) ($span->description ?? $trace->name()),
                spanIds: [$span->spanId],
                timeLostUs: $span->durationUs - self::LONG_TASK_US,
                evidence: [
                    'duration_us' => $span->durationUs,
                    'blocking_us' => $span->durationUs - self::LONG_TASK_US,
                ],
            );
        }

        return $findings;
    }

    /**
     * Woran der Browser hing.
     *
     * Eine lange Aufgabe hat oft keine Beschreibung — der Browser weiß, dass
     * gerechnet wurde, aber nicht wo. Dann tritt der Name des Ablaufs an ihre
     * Stelle: „irgendwas in dieser Ansicht blockiert" ist eine brauchbare
     * Aussage, „irgendwas blockiert" ist keine.
     */
    private function subject(SpanRecord $span, TraceSnapshot $trace): string
    {
        $description = trim((string) $span->description);

        return $description !== '' ? $description : $trace->name();
    }
}
