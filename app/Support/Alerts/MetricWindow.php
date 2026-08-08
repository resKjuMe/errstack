<?php

namespace App\Support\Alerts;

use App\Models\MetricAlert;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Das Zeitfenster, über das ein Alarm rechnet: von wann bis wann.
 *
 * Eigener Gegenstand und nicht zwei Zeitpunkte als Parameter, weil dasselbe
 * Fenster an drei Stellen gebraucht wird — für den aktuellen Wert, für den
 * Vergleichswert der Vorwoche und für jeden Balken der Vorschaugrafik. Wo es
 * dreimal gebaut wird, läuft es irgendwann auseinander.
 *
 * **Beide Grenzen liegen auf vollen Minuten.** Die Antwortzeit-Messungen sind in
 * Minuten-Fenstern vorberechnet ({@see Transaction::BUCKET_SECONDS});
 * ein Fenster, das mitten in einer Minute anfängt, träfe das erste und letzte
 * dieser Fenster nur halb und würde sie trotzdem ganz zählen. Der Preis ist eine
 * Verzögerung von weniger als einer Minute — die hat der minütliche Zeitplan
 * ohnehin.
 *
 * **Oben offen** (`from <= t < to`): sonst zählte das Fenster, in dem die
 * Auswertung läuft, in zwei aufeinanderfolgenden Läufen mit.
 */
final class MetricWindow
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /**
     * Das Fenster, das gerade eben zu Ende gegangen ist.
     */
    public static function endingAt(CarbonImmutable $now, int $minutes): self
    {
        $to = $now->utc()->startOfMinute();

        return new self($to->subMinutes($minutes), $to);
    }

    public static function forAlert(MetricAlert $alert, CarbonImmutable $now): self
    {
        return self::endingAt($now, $alert->window_minutes);
    }

    /**
     * Dasselbe Fenster, um eine Anzahl Tage zurückversetzt — der Vergleich mit
     * der Vorwoche.
     */
    public function shiftedDays(int $days): self
    {
        return new self($this->from->subDays($days), $this->to->subDays($days));
    }

    /**
     * Das Fenster, das unmittelbar vor diesem lag — der Schritt, mit dem die
     * Vorschaugrafik rückwärts läuft.
     */
    public function previous(): self
    {
        $length = $this->minutes();

        return new self($this->from->subMinutes($length), $this->from);
    }

    public function minutes(): int
    {
        return (int) round(($this->to->getTimestamp() - $this->from->getTimestamp()) / 60);
    }
}
