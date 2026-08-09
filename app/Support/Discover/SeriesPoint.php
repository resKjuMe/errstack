<?php

namespace App\Support\Discover;

use Carbon\CarbonImmutable;

/**
 * Eine Stützstelle einer Zeitreihe: der Anfang ihres Abschnitts und die Werte darin.
 *
 * Der Zeitpunkt ist der **Anfang** und nicht die Mitte oder das Ende — die
 * Beschriftung „12:00" über einem Balken, der die Stunde ab 12 Uhr zeigt, ist die
 * Lesart, die jede Grafik der Anwendung benutzt.
 */
final class SeriesPoint
{
    /**
     * @param  array<string, float|null>  $values
     */
    public function __construct(
        public readonly CarbonImmutable $at,
        public readonly array $values,
    ) {}

    /**
     * @return array{at: string, values: array<string, float|null>}
     */
    public function toArray(): array
    {
        return ['at' => $this->at->toIso8601ZuluString(), 'values' => $this->values];
    }
}
