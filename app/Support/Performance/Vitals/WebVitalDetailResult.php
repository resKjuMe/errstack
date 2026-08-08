<?php

namespace App\Support\Performance\Vitals;

use App\Enums\CountPeriod;
use App\Enums\WebVital;

/**
 * Was die Detailseite eines Ladeerlebnisses anzeigt — fertig für die
 * Oberfläche.
 *
 * Eine eigene Klasse zwischen Auswertung und Controller, damit der Controller
 * nichts umrechnet: was hier steht, geht so hinaus. Der Umweg lohnt sich, weil
 * die Seite mehrere Teile hat, die getrennt entstehen (Kennzahlen, Verlauf,
 * Aufschlüsselungen) und gemeinsam gelesen werden.
 */
final class WebVitalDetailResult
{
    /**
     * @param  array<string, VitalSummary>  $summaries  Messwert-Schlüssel →
     *                                                  Zusammenfassung, alle
     *                                                  Messwerte enthalten.
     * @param  list<array{from: int, to: int|null, count: int}>  $histogram  Die
     *                                                                       Verteilung des gewählten
     *                                                                       Messwerts.
     * @param  list<array{window: string, count: int, value: int|null, rating: string|null}>  $series
     * @param  list<VitalFacet>  $facets
     */
    public function __construct(
        public readonly string $name,
        public readonly WebVital $selected,
        public readonly array $summaries,
        public readonly array $histogram,
        public readonly CountPeriod $seriesPeriod,
        public readonly array $series,
        public readonly array $facets,
        public readonly int $sampledTransactions,
        public readonly int $sampleLimit,
    ) {}

    /**
     * Wurde für diese Seite im Zeitraum überhaupt etwas gemessen?
     *
     * Die Frage entscheidet, ob die Seite ihren Leerzustand zeigt — und der ist
     * hier keine Nebensache, sondern eine der zugesagten Auskünfte: eine Seite
     * ohne Messwerte muss als solche zu erkennen sein und nicht als eine mit
     * lauter Nullen.
     */
    public function hasData(): bool
    {
        foreach ($this->summaries as $summary) {
            if ($summary->count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'selected' => $this->selected->value,
            'hasData' => $this->hasData(),
            'vitals' => array_map(
                static fn (VitalSummary $summary): array => $summary->toArray(),
                $this->summaries,
            ),
            'histogram' => $this->histogram,
            'series' => [
                'period' => $this->seriesPeriod->value,
                'points' => $this->series,
            ],
            'facets' => array_map(
                static fn (VitalFacet $facet): array => $facet->toArray(),
                $this->facets,
            ),
            // Die Größe der Stichprobe wird mitgeschickt und nicht verschwiegen:
            // eine Aufschlüsselung aus 500 von 40.000 Ladevorgängen ist eine
            // andere Auskunft als eine aus allen, und wer sie liest, soll das
            // wissen.
            'sampledTransactions' => $this->sampledTransactions,
            'sampleLimit' => $this->sampleLimit,
        ];
    }
}
