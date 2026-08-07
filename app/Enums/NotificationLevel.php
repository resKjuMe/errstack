<?php

namespace App\Enums;

/**
 * Dringlichkeit einer Benachrichtigung. Der Kanal übersetzt sie in seine
 * eigene Darstellung — Slack und Discord färben den Anhang, Teams die
 * Kopfleiste, die E-Mail schreibt sie aus.
 */
enum NotificationLevel: string
{
    /** Reine Information, kein Handlungsbedarf. */
    case Info = 'info';

    /** Auffällig, aber (noch) kein Ausfall. */
    case Warning = 'warning';

    /** Etwas ist kaputt. */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::Warning => 'Warnung',
            self::Error => 'Fehler',
        };
    }

    /**
     * Farbe als Hex-Wert ohne Raute. Slack, Discord und Teams erwarten alle
     * eine Farbe, nur in unterschiedlicher Schreibweise — die Umrechnung
     * übernimmt der jeweilige Kanal.
     */
    public function color(): string
    {
        return match ($this) {
            self::Info => '3b82f6',
            self::Warning => 'f59e0b',
            self::Error => 'ef4444',
        };
    }
}
