<?php

namespace App\Support\Alerts;

/**
 * Was aus einem Zeitfenster herauskam: ein Wert — oder ausdrücklich keiner.
 *
 * Der Unterschied zwischen „null" und „unbekannt" ist der Kern dieser Klasse
 * und der Grund, warum sie kein blanker `?float` ist. Eine Anzahl von null ist
 * eine Aussage: es ist nichts passiert, und ein Alarm auf der Fehleranzahl darf
 * daraufhin Entwarnung geben. Eine Antwortzeit ohne Messungen ist dagegen keine
 * Aussage — wer daraus „0 ms" macht, löst jeden Alarm auf, dessen Anwendung
 * gerade **gar nichts** mehr beantwortet.
 *
 * Deshalb trägt die Ablesung zusätzlich, auf wie vielen Messungen sie beruht:
 * daran entscheidet sich, ob eine Quote überhaupt etwas bedeutet.
 */
final class MetricReading
{
    private function __construct(
        public readonly ?float $value,
        public readonly int $samples,
    ) {}

    public static function of(float $value, int $samples): self
    {
        return new self($value, $samples);
    }

    /**
     * Keine Aussage möglich — zu wenige oder gar keine Messungen.
     */
    public static function unknown(int $samples = 0): self
    {
        return new self(null, $samples);
    }

    public function isKnown(): bool
    {
        return $this->value !== null;
    }
}
