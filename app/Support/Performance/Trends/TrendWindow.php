<?php

namespace App\Support\Performance\Trends;

use App\Support\Performance\DurationHistogram;
use Carbon\CarbonImmutable;

/**
 * Ein Zeitfenster einer Transaktion, wie die Erkennung es braucht: Anfang,
 * Anzahl der Messungen, Verteilung — und das daraus gelesene p95.
 *
 * Das p95 steht **im Gegenstand** und wird nicht bei jedem Zugriff neu
 * gerechnet: der Bruchpunkt-Suchlauf vergleicht dieselben Fenster über alle
 * Kandidaten hinweg, und die Rechnung über die Verteilung wäre bei 168 Fenstern
 * und 160 Kandidaten einige zehntausend Durchläufe für ein Ergebnis, das sich
 * nicht ändert.
 */
final readonly class TrendWindow
{
    public ?int $p95Us;

    public function __construct(
        public CarbonImmutable $at,
        public int $count,
        public DurationHistogram $histogram,
    ) {
        $this->p95Us = $histogram->percentile(0.95);
    }
}
