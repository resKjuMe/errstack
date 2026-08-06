<?php

namespace App\Policies;

use App\Models\NotificationChannel;
use App\Models\User;

/**
 * Rechte an einem Benachrichtigungsweg. Ein Kanal gehört immer einer
 * Organisation — die Rechte ergeben sich aus der Rolle dort.
 */
class NotificationChannelPolicy
{
    /**
     * Sehen darf jedes Mitglied: wohin gemeldet wird und ob die Zustellung
     * klappt, geht alle an. Die Zugangsdaten selbst bekommt niemand zu sehen.
     */
    public function view(User $user, NotificationChannel $channel): bool
    {
        return $channel->organization->hasMember($user);
    }

    public function update(User $user, NotificationChannel $channel): bool
    {
        return $user->can('manageNotifications', $channel->organization);
    }

    public function delete(User $user, NotificationChannel $channel): bool
    {
        return $user->can('manageNotifications', $channel->organization);
    }

    /**
     * Testnachricht senden und fehlgeschlagene Zustellung wiederholen. Beides
     * löst echten Versand aus und bleibt deshalb bei der Verwaltung.
     */
    public function send(User $user, NotificationChannel $channel): bool
    {
        return $user->can('manageNotifications', $channel->organization);
    }
}
