<?php

namespace App\Enums;

/**
 * In welche Richtung eine Schwelle verletzt wird.
 *
 * Nicht jede Kennzahl ist nach oben gefährlich: eine steigende Fehlerrate ist
 * schlecht, ein einbrechender Durchsatz ebenso — und der fällt. Ohne diese
 * Angabe ließe sich „es kommt gar nichts mehr an" nicht überwachen, obwohl das
 * der Ausfall ist, den man am spätesten von selbst bemerkt.
 */
enum AlertDirection: string
{
    /** Alarm, sobald der Wert die Schwelle **überschreitet**. */
    case Above = 'above';

    /** Alarm, sobald der Wert die Schwelle **unterschreitet**. */
    case Below = 'below';

    public function label(): string
    {
        return __('enums.alert_direction.'.$this->value);
    }

    /**
     * Verletzt dieser Wert die Schwelle?
     *
     * Einschließlich der Schwelle selbst: „mehr als 10 Fehler" wird von
     * niemandem als „ab 11" gelesen, und eine Schwelle, die genau bei ihrem Wert
     * nicht greift, ist die Sorte Feinheit, die man im Ernstfall nicht bemerkt.
     */
    public function breaches(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value >= $threshold,
            self::Below => $value <= $threshold,
        };
    }

    /**
     * Liegt der Wert jenseits der Auflösungsschwelle, ist der Alarm also
     * beendet?
     *
     * Die Umkehrung von {@see breaches()} und ausdrücklich **ohne** die
     * Schwelle selbst: der Wert muss die Grenze wirklich hinter sich lassen.
     * Genau darin liegt der Sinn einer eigenen Auflösungsschwelle — ein Wert,
     * der um die Grenze pendelt, soll nicht abwechselnd Alarm und Entwarnung
     * schicken.
     */
    public function clears(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value < $threshold,
            self::Below => $value > $threshold,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
