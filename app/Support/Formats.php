<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Datums-, Zeit- und Zahlenangaben in der Schreibweise der gewählten Sprache.
 *
 * Die Muster stehen in `lang/<sprache>/formats.php`, damit eine neue Sprache
 * ohne Code-Änderung ihre eigene Schreibweise mitbringt (`d.m.Y` gegen
 * `M j, Y`). Monats- und Wochentagsnamen übersetzt `translatedFormat` anhand
 * der aktiven Sprache.
 */
final class Formats
{
    public static function date(?CarbonInterface $value): ?string
    {
        return $value?->translatedFormat(__('formats.date'));
    }

    public static function dateTime(?CarbonInterface $value): ?string
    {
        return $value?->translatedFormat(__('formats.date_time'));
    }

    public static function dateTimeSeconds(?CarbonInterface $value): ?string
    {
        return $value?->translatedFormat(__('formats.date_time_seconds'));
    }

    /**
     * Eine Laufzeit aus Millisekunden, in der Einheit, die zur Größenordnung
     * passt.
     *
     * Der Wechsel ist der eigentliche Zweck: ein Job, der vier Millisekunden
     * braucht, und einer, der zwei Stunden läuft, stehen in derselben Spalte
     * untereinander. In einer gemeinsamen Einheit wäre die eine Zahl unlesbar
     * lang und die andere Null.
     */
    public static function duration(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return __('formats.duration_milliseconds', ['value' => self::number($milliseconds)]);
        }

        $seconds = $milliseconds / 1000;

        if ($seconds < 60) {
            // Eine Nachkommastelle unterhalb von zehn Sekunden: dort ist der
            // Unterschied zwischen 1 und 1,8 Sekunden noch von Belang.
            return __('formats.duration_seconds', [
                'value' => self::number($seconds, $seconds < 10 ? 1 : 0),
            ]);
        }

        $minutes = $seconds / 60;

        if ($minutes < 60) {
            return __('formats.duration_minutes', ['value' => self::number($minutes, 1)]);
        }

        return __('formats.duration_hours', ['value' => self::number($minutes / 60, 1)]);
    }

    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format(
            $value,
            $decimals,
            __('formats.decimal_separator'),
            __('formats.thousands_separator'),
        );
    }
}
