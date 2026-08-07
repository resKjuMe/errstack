<?php

namespace App\Enums;

/**
 * Weg, auf dem eine Benachrichtigung bei einem einzelnen Nutzer ankommt.
 *
 * Nicht zu verwechseln mit den Benachrichtigungswegen einer Organisation
 * (App\Models\NotificationChannel): die gehören der Organisation und gehen an
 * feste Adressen — Slack-Räume, Verteiler, Webhooks. Hier geht es um die
 * Person: was landet in *meinem* Posteingang.
 */
enum NotificationTransport: string
{
    /** E-Mail an die Adresse des Kontos. */
    case Mail = 'mail';

    /** Im Benachrichtigungs-Postfach der Anwendung. */
    case InApp = 'in_app';

    public function label(): string
    {
        return __('enums.notification_transport.'.$this->value);
    }

    public function description(): string
    {
        return __('enums.notification_transport_description.'.$this->value);
    }
}
