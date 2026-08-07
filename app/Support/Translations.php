<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Die Übersetzungstabelle, die die Oberfläche braucht.
 *
 * Übersetzt wird ausschließlich serverseitig; React bekommt nur das fertige
 * Ergebnis. Die Tabelle hängt als Inertia-Shared-Prop an jeder Antwort — damit
 * ein Sprachwechsel sofort greift, ohne dass eine Seite ihre Texte selbst
 * nachladen müsste.
 *
 * Geliefert werden die Gruppen aus {@see self::GROUPS}, flach zusammengelegt
 * (`projects.create.title`). Was nur der Server braucht (Mail-Texte,
 * Validierung, Protokoll-Export), steht bewusst nicht darin.
 */
final class Translations
{
    /** Sprachgruppen, die in der Oberfläche gebraucht werden. */
    public const GROUPS = [
        'api_tokens',
        'audit',
        'auth_ui',
        'common',
        'components',
        'crons',
        'dashboard',
        'filters',
        'grouping',
        'inbound',
        'invitations',
        'nav',
        'notifications',
        'organizations',
        'privacy',
        'profile',
        'project_keys',
        'projects',
        'teams',
    ];

    /**
     * @return array<string, string>
     */
    public static function forInterface(): array
    {
        $strings = [];

        foreach (self::GROUPS as $group) {
            $lines = __($group);

            if (! is_array($lines)) {
                continue;
            }

            foreach (Arr::dot($lines) as $key => $value) {
                if (is_string($value)) {
                    $strings[$group.'.'.$key] = $value;
                }
            }
        }

        return $strings;
    }

    /**
     * Schreibweisen für Zahlen und Zeitpunkte, die im Browser entstehen.
     *
     * @return array<string, string>
     */
    public static function formats(): array
    {
        $formats = __('formats');

        return is_array($formats) ? array_filter($formats, 'is_string') : [];
    }
}
