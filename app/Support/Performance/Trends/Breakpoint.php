<?php

namespace App\Support\Performance\Trends;

use App\Enums\TrendDirection;
use Carbon\CarbonImmutable;

/**
 * Das Ergebnis eines Suchlaufs: an dieser Stelle ist die Transaktion
 * umgeschlagen, von dieser Höhe auf jene.
 *
 * Ein reiner Befund ohne Bezug zu Projekt oder Transaktion — wer ihn hat, weiß
 * noch nicht, wovon er handelt. Das ist Absicht: die Rechnung soll sich prüfen
 * lassen, ohne dass eine Datenbank daneben steht.
 */
final readonly class Breakpoint
{
    public function __construct(
        public TrendDirection $direction,
        /** Anfang des ersten Fensters **nach** dem Bruch. */
        public CarbonImmutable $at,
        public int $beforeP95Us,
        public int $afterP95Us,
        public int $beforeCount,
        public int $afterCount,
        /** 0,2 heißt „20 % langsamer", -0,2 „20 % schneller". */
        public float $changeRatio,
        /** Die Aussagekraft als z-Wert des Rangsummentests, immer positiv. */
        public float $zScore,
    ) {}

    public function isRegression(): bool
    {
        return $this->direction === TrendDirection::Worse;
    }
}
