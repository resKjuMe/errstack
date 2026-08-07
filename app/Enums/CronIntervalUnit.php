<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Einheit eines Intervall-Zeitplans („alle 15 **Minuten**").
 *
 * Die Werte sind Sentrys Einheiten aus `monitor_config.schedule.unit` — dort
 * stehen sie im Singular, und genau so schickt sie ein SDK.
 *
 * Gerechnet wird über Carbon und nicht über eine Sekundenzahl je Einheit: ein
 * Monat hat keine feste Länge, und ein Tag hat an der Zeitumstellung 23 oder 25
 * Stunden. Wer „täglich" mit 86400 Sekunden gleichsetzt, verschiebt einen
 * nächtlichen Job zweimal im Jahr um eine Stunde — und meldet ihn dann als
 * verpasst.
 */
enum CronIntervalUnit: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return __('enums.cron_interval_unit.'.$this->value);
    }

    /**
     * Rückt einen Zeitpunkt um `$value` Einheiten vor.
     */
    public function advance(Carbon $from, int $value): Carbon
    {
        return match ($this) {
            self::Minute => $from->copy()->addMinutes($value),
            self::Hour => $from->copy()->addHours($value),
            self::Day => $from->copy()->addDays($value),
            self::Week => $from->copy()->addWeeks($value),
            self::Month => $from->copy()->addMonthsNoOverflow($value),
            self::Year => $from->copy()->addYears($value),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $unit): array => ['value' => $unit->value, 'label' => $unit->label()],
            self::cases(),
        );
    }
}
