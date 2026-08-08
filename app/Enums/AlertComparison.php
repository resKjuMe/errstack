<?php

namespace App\Enums;

/**
 * Woran die Schwelle gemessen wird: am Wert selbst oder an seiner Veränderung.
 *
 * Der absolute Vergleich beantwortet „mehr als 10 Fehler in 5 Minuten". Er ist
 * das, was man einstellt, wenn man die Größenordnung kennt.
 *
 * Der Wochenvergleich beantwortet „doppelt so viele wie sonst um diese Zeit".
 * Er ist das, was man braucht, wenn man sie **nicht** kennt: eine Anwendung mit
 * Tagesgang und Wochenrhythmus hat am Montagvormittag ganz andere Zahlen als am
 * Sonntagabend, und eine feste Schwelle ist dann entweder nachts blind oder
 * tagsüber ein Dauerton. Verglichen wird deshalb mit **demselben Zeitfenster
 * sieben Tage zuvor** und nicht mit dem Fenster davor — nur so fällt der
 * Tagesgang aus der Rechnung heraus.
 */
enum AlertComparison: string
{
    /** Die Schwelle gilt für den Wert selbst. */
    case Absolute = 'absolute';

    /** Die Schwelle gilt für die Veränderung gegenüber der Vorwoche, in Prozent. */
    case PercentChangeWeek = 'percent_change_week';

    public function label(): string
    {
        return __('enums.alert_comparison.'.$this->value);
    }

    /**
     * Um wie viele Tage der Vergleichszeitraum zurückliegt.
     */
    public function baselineOffsetDays(): ?int
    {
        return $this === self::PercentChangeWeek ? 7 : null;
    }

    /**
     * Die Einheit der Schwelle bei diesem Vergleich — beim Wochenvergleich
     * immer Prozent, egal welche Kennzahl darunter liegt.
     */
    public function unitFor(AlertMetric $metric): string
    {
        return $this === self::PercentChangeWeek ? '%' : $metric->unit();
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
