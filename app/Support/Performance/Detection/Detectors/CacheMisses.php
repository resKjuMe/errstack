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
 * Cache-Fehlgriffe: Nachschläge im Zwischenspeicher, die ins Leere gehen.
 *
 * Ein einzelner Fehlgriff ist kein Problem — der erste Zugriff ist immer einer.
 * Auffällig wird es, wenn ein Ablauf **immer wieder** danebengreift: dann ist
 * entweder der Schlüssel falsch gebaut (er enthält etwas, das sich bei jedem
 * Aufruf ändert), die Ablaufzeit zu kurz, oder der Eintrag wird nie geschrieben.
 * Alle drei sind Fehler im Programm und keine Eigenart der Last.
 *
 * **Die gemeldete Zeit ist ausdrücklich eine Untergrenze.** Gezählt wird die
 * Dauer der ins Leere gegangenen Nachschläge — nicht die Neuberechnung, die
 * ihnen folgt, und die ist der eigentliche Preis. Sie steht in keinem eigenen
 * Schritt und wäre nur zu raten: welcher Teil der Abfragen danach durch den
 * Treffer entfallen wäre, weiß der Ablauf nicht. Lieber eine zu kleine Zahl,
 * die stimmt, als eine große, die geschätzt ist — die Rangfolge der Einträge
 * ist eine Aussage darüber, was sich zu tun lohnt, und geschätzte Werte machen
 * sie wertlos.
 *
 * Gruppiert wird nach **Schlüsselform**, nicht nach Schlüssel: `nutzer:4711`
 * und `nutzer:4712` sind derselbe Nachschlag im Programm
 * ({@see QueryShape::of()}).
 */
final class CacheMisses implements Detector
{
    /**
     * Die Vorgänge, unter denen die SDKs einen Zwischenspeicher-Zugriff melden.
     */
    private const CACHE_OPS = ['cache', 'db.redis', 'db.cache'];

    public function problem(): PerformanceProblem
    {
        return PerformanceProblem::CacheMisses;
    }

    public function detect(TraceSnapshot $trace, Thresholds $thresholds): array
    {
        $minCount = $thresholds->count($this->problem());
        $minTotalUs = $thresholds->durationUs($this->problem(), 'min_total_ms');

        /** @var array<string, array{misses: list<SpanRecord>, hits: int}> $groups */
        $groups = [];

        foreach ($trace->ofOp(self::CACHE_OPS) as $span) {
            $hit = $this->wasHit($span);

            // Ohne Angabe wird nichts gezählt. Ein Zugriff, bei dem das SDK
            // nicht sagt, ob er getroffen hat, ist kein Fehlgriff — er ist
            // unbekannt, und ein Erkenner, der Unbekanntes als Fehlgriff liest,
            // meldet jedes SDK an, das die Angabe nicht mitschickt.
            if ($hit === null) {
                continue;
            }

            $key = $this->keyShape($span);

            $groups[$key] ??= ['misses' => [], 'hits' => 0];

            if ($hit) {
                $groups[$key]['hits']++;

                continue;
            }

            $groups[$key]['misses'][] = $span;
        }

        $findings = [];

        foreach ($groups as $key => $group) {
            $misses = $group['misses'];

            if (count($misses) < $minCount) {
                continue;
            }

            $total = array_sum(array_map(static fn (SpanRecord $span): int => $span->durationUs, $misses));

            if ($total < $minTotalUs) {
                continue;
            }

            $findings[] = new Finding(
                problem: $this->problem(),
                subject: $key,
                description: (string) ($misses[0]->description ?? $key),
                spanIds: array_map(static fn (SpanRecord $span): string => $span->spanId, $misses),
                timeLostUs: $total,
                evidence: [
                    'misses' => count($misses),
                    'hits' => $group['hits'],
                    'total_us' => $total,
                ],
            );
        }

        return $findings;
    }

    /**
     * Hat der Nachschlag getroffen? `null` heißt „das SDK sagt es nicht".
     */
    private function wasHit(SpanRecord $span): ?bool
    {
        return $span->boolData('cache.hit') ?? $span->boolData('cache_hit');
    }

    /**
     * Die Form des nachgeschlagenen Schlüssels.
     */
    private function keyShape(SpanRecord $span): string
    {
        $key = $span->stringData('cache.key') ?? $span->description;

        $shape = QueryShape::of($key);

        return $shape !== '' ? $shape : (string) $span->op;
    }
}
