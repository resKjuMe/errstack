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
