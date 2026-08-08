<?php

namespace App\Support\Digests;

use App\Enums\NotificationEventType;
use App\Models\NotificationDigestEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationMessage;

/**
 * Die Entscheidung, ob eine Meldung warten darf — und der Wartekorb selbst.
 *
 * Sie fällt an genau einer Stelle ({@see App\Notifications\NotificationDispatcher}),
 * und zwar bevor irgendetwas eingereiht wird. Das ist Absicht: eine Bündelung,
 * die erst im Versand greift, hätte die Mail schon geschrieben und müsste sie
 * wieder einsammeln.
 *
 * **Drei Fragen, und alle drei müssen mit Ja beantwortet sein:**
 *
 *   1. Bündelt das Projekt überhaupt? Ein Fenster von null Minuten heißt Nein,
 *      und das ist die Vorgabe — kein Projekt benachrichtigt durch diese
 *      Aufgabe plötzlich anders als gestern.
 *   2. Will der Empfänger es? Er darf für sich widersprechen, auch wenn das
 *      Projekt bündelt.
 *   3. Duldet die Meldung Aufschub? Eine dringende Meldung geht immer sofort
 *      hinaus. Das ist die eine Zusage, die eine Bündelung geben muss, sonst
 *      ist sie ein Ausfall mit Verzögerung.
 *
 * Ohne Projekt wird nicht gebündelt: das Fenster ist eine Projekt-Einstellung,
 * und eine Meldung, die keinem Projekt gehört (eine Kontingent-Warnung etwa),
 * hat keine, an der sie sich ausrichten könnte.
 */
final class DigestBundler
{
    /**
     * Legt die Meldung in den Wartekorb — oder gibt zurück, dass sie sofort
     * hinausgehen soll.
     *
     * @return bool ob die Meldung übernommen wurde und der Aufrufer nichts mehr
     *              zu tun hat
     */
    public function hold(
        User $user,
        NotificationMessage $message,
        NotificationEventType $event,
        ?Project $project,
        ?Organization $organization,
    ): bool {
        if ($project === null || ! $this->applies($user, $message, $project)) {
            return false;
        }

        NotificationDigestEntry::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'organization_id' => $organization === null ? $project->organization_id : $organization->id,
            'event_type' => $event->value,
            'payload' => $message->toArray(),
        ]);

        return true;
    }

    /**
     * Greift die Bündelung für diese Meldung an diesen Empfänger?
     */
    private function applies(User $user, NotificationMessage $message, Project $project): bool
    {
        if ($message->urgent) {
            return false;
        }

        if ($project->digest_window_minutes < 1) {
            return false;
        }

        return $user->notificationSettingOrDefault()->digest_enabled;
    }
}
