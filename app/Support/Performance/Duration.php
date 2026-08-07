<?php

namespace App\Support\Performance;

use Carbon\CarbonImmutable;

/**
 * Die Dauer zwischen zwei gemeldeten Zeitpunkten, in Mikrosekunden.
 */
final class Duration
{
    /**
     * Nie negativ.
     *
     * Die Zeitpunkte kommen aus der überwachten Anwendung, und dort geht die Uhr
     * nicht immer vorwärts: eine Zeitumstellung, eine Korrektur durch NTP oder
     * zwei Dienste mit auseinanderlaufenden Uhren erzeugen ein Ende vor dem
     * Anfang. Abgelegt ist die Dauer in einer Spalte ohne Vorzeichen — eine
     * negative Zahl würde dort zur größtmöglichen und die Übersicht mit einer
     * Antwortzeit von einigen Hunderttausend Jahren anführen.
     */
    public static function between(CarbonImmutable $startedAt, CarbonImmutable $finishedAt): int
    {
        $us = (int) round($finishedAt->getPreciseTimestamp(6) - $startedAt->getPreciseTimestamp(6));

        return max(0, $us);
    }
}
