<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Die Auflösung eines Zeitreihen-Zählers: Stunde oder Tag.
 *
 * Zwei Auflösungen und nicht eine, weil eine Verlaufsgrafik zwei sehr
 * verschiedene Fragen beantworten soll: „was ist heute Mittag passiert?" und
 * „wie sieht das seit einem Vierteljahr aus?". Mit nur Stunden wären 90 Tage
 * 2.160 Zeilen je Eintrag und Diagramm — bei jedem Seitenaufruf zu summieren.
 * Mit nur Tagen verschwände der Ausschlag von zwanzig Minuten in einem Balken.
 *
 * Die Fenster werden **abgeschnitten und nicht gerundet**: sonst fiele ein
 * Ereignis um 10:31 in die Stunde 11, die noch gar nicht begonnen hat.
 */
enum CountPeriod: string
{
    case Hour = 'hour';

    case Day = 'day';

    /**
     * Der Anfang des Fensters, in das ein Zeitpunkt fällt.
     *
     * Immer in UTC, unabhängig davon, in welcher Zeitzone der Zeitpunkt
     * ankommt: die Fenster zweier Arbeiter müssen dieselben sein, sonst zählen
     * sie in verschiedene Zeilen und keine der beiden stimmt. Wie die Grafik
     * das später beschriftet — in der Zeitzone des Betrachters — ist eine
     * Frage der Anzeige und nicht der Ablage.
     */
    public function windowFor(CarbonImmutable $at): CarbonImmutable
    {
        $at = $at->utc();

        return match ($this) {
            self::Hour => $at->startOfHour(),
            self::Day => $at->startOfDay(),
        };
    }

    /**
     * Wie lange Zeilen dieser Auflösung aufbewahrt werden.
     *
     * Beide 90 Tage, weil die Zusage der Aufgabe ein Verlauf über 90 Tage ist —
     * und die beiden Auflösungen dabei nicht gegeneinander ausgespielt werden
     * dürfen: eine Stunden-Reihe, die nach 7 Tagen endet, macht aus der Grafik
     * eines Vierteljahres eine mit einem Loch am Anfang. Der Preis sind 2.160
     * Zeilen je Eintrag; das ist wenig gegen die Ereignisse, aus denen sie
     * entstanden sind.
     */
    public function retentionDays(): int
    {
        return 90;
    }

    public function label(): string
    {
        return __('enums.count_period.'.$this->value);
    }
}
