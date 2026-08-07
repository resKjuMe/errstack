<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Die Sprachen, in denen die Oberfläche vorliegt — und wie aus Konto und
 * Browser die gültige wird.
 *
 * Reihenfolge der Quellen: die am Konto gespeicherte Wahl, sonst der
 * `Accept-Language`-Kopf des Browsers, sonst die Vorgabe aus der Konfiguration.
 * Damit sieht auch ein Gast auf der Anmeldeseite seine Sprache, ohne dass er
 * etwas einstellen müsste.
 */
final class Locales
{
    /** Sprachen, in die die Oberfläche übersetzt ist. */
    public const SUPPORTED = ['de', 'en'];

    /**
     * Auswahlfeld der Oberfläche.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $locale) => [
                'value' => $locale,
                'label' => __('common.locales.'.$locale),
            ],
            self::SUPPORTED,
        );
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::SUPPORTED, true);
    }

    public static function fallback(): string
    {
        $default = config('app.locale');

        return self::isSupported($default) ? $default : self::SUPPORTED[0];
    }

    public static function resolve(Request $request): string
    {
        $stored = $request->user()?->locale;

        if (self::isSupported($stored)) {
            return $stored;
        }

        return self::fromBrowser($request) ?? self::fallback();
    }

    /**
     * Beste unterstützte Sprache aus `Accept-Language`. Der Kopf nennt mehrere
     * Sprachen mit Gewicht (`de-AT,de;q=0.9,en;q=0.8`); Symfony sortiert danach,
     * hier zählt nur der Sprachteil vor dem Bindestrich.
     */
    private static function fromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $base = strtolower(explode('_', str_replace('-', '_', $language))[0]);

            if (self::isSupported($base)) {
                return $base;
            }
        }

        return null;
    }
}
