<?php

namespace App\Enums;

/**
 * Warteschlangen der Anwendung. Die Reihenfolge der Cases ist zugleich die
 * Abarbeitungs-Priorität des Workers: `ingest` zuerst, damit eingehende
 * Fehlermeldungen nie hinter Benachrichtigungen warten.
 */
enum QueueName: string
{
    /** Entgegennahme und Verarbeitung eingehender Fehlermeldungen. */
    case Ingest = 'ingest';

    /** Benachrichtigungen und Broadcasts an offene Ansichten. */
    case Notifications = 'notifications';

    /** Alles Übrige. */
    case Default = 'default';

    /**
     * Warteschlangen in Prioritätsreihenfolge, wie sie `queue:work --queue=…`
     * erwartet: `ingest,notifications,default`.
     */
    public static function priority(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
