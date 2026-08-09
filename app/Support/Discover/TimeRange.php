<?php

namespace App\Support\Discover;

use App\Support\Alerts\MetricWindow;
use Carbon\CarbonImmutable;

/**
 * Der Zeitraum einer Auswertung: von wann bis wann.
 *
 * **Oben offen** (`from <= t < to`), wie das Fenster der Alarme
 * ({@see MetricWindow}): sonst zählt die Messung auf der
 * Grenze in zwei aufeinanderfolgenden Zeiträumen mit, und die Summe der Stunden
 * ist größer als der Tag.
 *
 * **Immer UTC.** Die Zeitzone des Betrachters gehört an die Anzeige und an die
 * Auslegung von Datumsangaben in der Suche, nicht an den Zeitraum selbst — zwei
 * Auswertungen desselben Zeitraums aus verschiedenen Zeitzonen müssen dieselben
 * Zahlen ergeben, sonst ist ein geteilter Link eine andere Auswertung.
 */
final class TimeRange
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function of(CarbonImmutable $from, CarbonImmutable $to): self
    {
        $from = $from->utc();
        $to = $to->utc();

        if ($to <= $from) {
            throw DiscoverException::invalid('Der Zeitraum endet vor seinem Anfang.');
        }

        return new self($from, $to);
    }

    public static function lastMinutes(int $minutes, CarbonImmutable $now): self
    {
        return self::of($now->utc()->subMinutes($minutes), $now->utc());
    }

    public static function lastHours(int $hours, CarbonImmutable $now): self
    {
        return self::lastMinutes($hours * 60, $now);
    }

    public static function lastDays(int $days, CarbonImmutable $now): self
    {
        return self::lastMinutes($days * 24 * 60, $now);
    }

    public function seconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp();
    }

    /**
     * Derselbe Zeitraum, auf ein Raster gelegt.
     *
     * Der Weg zu einem Zwischenspeicher, der bei einem gleitenden Zeitraum
     * überhaupt trifft: „die letzten 24 Stunden" ist bei jedem Aufruf ein
     * anderer Zeitraum und damit eine andere Abfrage. Abgeschnitten wird nach
     * unten — ein Raster, das nach oben rundet, würde einen Zeitraum ausweisen,
     * der noch nicht zu Ende ist.
     */
    public function snapped(int $seconds): self
    {
        if ($seconds < 2) {
            return $this;
        }

        return new self(self::floor($this->from, $seconds), self::floor($this->to, $seconds));
    }

    private static function floor(CarbonImmutable $at, int $seconds): CarbonImmutable
    {
        $timestamp = $at->getTimestamp();

        return CarbonImmutable::createFromTimestamp($timestamp - $timestamp % $seconds)->utc();
    }

    /**
     * Die Form für den Fingerabdruck einer Abfrage.
     *
     * @return array{from: int, to: int}
     */
    public function toFingerprint(): array
    {
        return ['from' => $this->from->getTimestamp(), 'to' => $this->to->getTimestamp()];
    }
}
