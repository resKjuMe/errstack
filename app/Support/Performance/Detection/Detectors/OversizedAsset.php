<?php

namespace App\Support\Performance\Detection\Detectors;

use App\Enums\PerformanceProblem;
use App\Support\Performance\Detection\Detector;
use App\Support\Performance\Detection\Finding;
use App\Support\Performance\Detection\QueryShape;
use App\Support\Performance\Detection\Thresholds;
use App\Support\Performance\Detection\TraceSnapshot;

/**
 * Eine übergroße oder unkomprimiert ausgelieferte Datei.
 *
 * Die Browser-SDKs melden je geladener Datei einen Schritt mit drei Größen:
 * `http.response_content_length` (was über die Leitung ging),
 * `http.decoded_response_content_length` (was danach im Speicher stand) und
 * `http.response_transfer_size` (mit Kopfzeilen). Aus dem Verhältnis der ersten
 * beiden ergibt sich, ob komprimiert wurde — und zwar ohne zu raten: wer
 * übertragene und entpackte Größe gleich meldet, hat nicht komprimiert.
 *
 * **Beide Fälle in einem Muster**, obwohl sie verschieden zu beheben sind — der
 * eine durch eine Einstellung im Web-Server, der andere durch ein kleineres
 * Bild. Der Grund ist die gemeinsame Frage dahinter: „welche Datei kostet die
 * Nutzer unnötig Ladezeit". Getrennt wären es zwei Listen, die man beide
 * durchsehen muss, und derselbe Übeltäter stünde oft in beiden. Woran es liegt,
 * sagt der Beleg.
 *
 * Die zweite Schwelle — eine Mindestdauer — ist kein Beiwerk: eine große Datei
 * aus dem Zwischenspeicher des Browsers ist in einer Millisekunde da und kostet
 * niemanden etwas. Ohne sie wäre die halbe Liste voll mit Dateien, die gar
 * nicht geladen wurden.
 */
final class OversizedAsset implements Detector
{
    /**
     * Die Vorgänge, unter denen ein Browser-SDK eine geladene Datei meldet.
     */
    private const RESOURCE_OPS = ['resource'];

    /**
     * Ab welchem Verhältnis von übertragener zu entpackter Größe etwas als
     * komprimiert gilt.
     *
     * Nicht exakt gleich, sondern 95 %: manche Server packen auch ein bereits
     * komprimiertes Bild noch einmal ein und sparen dabei ein paar Promille —
     * das ist keine Kompression, sondern Rauschen.
     */
    private const COMPRESSION_RATIO = 0.95;

    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::OversizedAsset;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minBytes = $thresholds->bytes($this->problem());
        $minDurationUs = $thresholds->durationUs($this->problem());

        $findings = [];

        foreach ($trace->ofOp(self::RESOURCE_OPS) as $span) {
            if ($span->durationUs < $minDurationUs) {
                continue;
            }

            $encoded = $span->intData('http.response_content_length')
                ?? $span->intData('http.response_transfer_size');
            $decoded = $span->intData('http.decoded_response_content_length');

            if ($encoded === null || $encoded <= 0) {
                continue;
            }

            $uncompressed = $this->isUncompressed($encoded, $decoded);

            // Entweder zu groß oder unkomprimiert — und im zweiten Fall erst ab
            // einem Zehntel der Größenschwelle. Sonst meldet der Erkenner jedes
            // unkomprimierte Symbol von zweihundert Byte.
            if ($encoded < $minBytes && ! ($uncompressed && $encoded >= intdiv($minBytes, 10))) {
                continue;
            }

            $target = QueryShape::ofUrl($span->description);

            $findings[] = new Finding(
                problem: $this->problem(),
                subject: $target !== '' ? $target : (string) $span->op,
                description: (string) ($span->description ?? $target),
                spanIds: [$span->spanId],
                // Die ganze Ladezeit: anders als bei einem fremden Dienst gibt
                // es hier keinen unvermeidbaren Sockel — eine Datei, die nicht
                // geladen werden muss, kostet nichts.
                timeLostUs: $span->durationUs,
                evidence: [
                    'encoded_bytes' => $encoded,
                    'decoded_bytes' => $decoded,
                    'uncompressed' => $uncompressed,
                    'duration_us' => $span->durationUs,
                ],
            );
        }

        return $findings;
    }

    /**
     * Wurde die Datei komprimiert ausgeliefert?
     *
     * Ohne entpackte Größe lautet die Antwort ausdrücklich „nicht bekannt" und
     * damit `false`: eine fehlende Angabe ist kein Beweis für ein Versäumnis,
     * und ein Erkenner, der Fehlendes als Fehler liest, meldet jedes SDK an,
     * das die Zahl nicht mitschickt.
     */
    private function isUncompressed(int $encoded, ?int $decoded): bool
    {
        if ($decoded === null || $decoded <= 0) {
            return false;
        }

        return $encoded >= $decoded * self::COMPRESSION_RATIO;
    }
}
