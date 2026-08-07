<?php

namespace App\Notifications;

use App\Enums\NotificationEventType;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Der Abmelde-Link, der in jeder persönlichen Mail steht.
 *
 * Er ist signiert und trägt den Anlass mit: wer ihn anklickt, muss sich weder
 * anmelden noch erst die richtige Einstellung suchen. Bewusst ohne Ablauf —
 * eine Mail wird auch nach Wochen noch gelesen, und ein toter Abmelde-Link
 * ist schlimmer als gar keiner.
 *
 * Die Signatur ist zugleich der Schutz: ohne sie könnte jeder fremde Konten
 * durch Hochzählen der Nummer stummschalten.
 */
final class UnsubscribeLink
{
    public static function for(User $user, NotificationEventType $event): string
    {
        return URL::signedRoute('notifications.unsubscribe', [
            'user' => $user->id,
            'event' => $event->value,
        ]);
    }
}
