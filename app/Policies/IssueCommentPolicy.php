<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;

/**
 * Wer kommentieren, ändern und löschen darf.
 *
 * **Schreiben darf, wer den Fehler sieht.** Dieselbe Abwägung wie bei den
 * Zustandsaktionen ({@see IssuePolicy}): ein eigenes Recht fürs Kommentieren
 * hieße, dass ein Teil des Teams eine Fehlerliste liest, zu der es nichts sagen
 * darf — und dann steht die Absprache in einem Chat, wo sie in einem Jahr
 * niemand mehr findet. Genau das soll der Verlauf verhindern.
 *
 * **Ändern darf nur, wer geschrieben hat.** Ein fremder Kommentar unter fremdem
 * Namen, nachträglich umformuliert, ist schlimmer als kein Kommentar: er sieht
 * echt aus. Auch die Verwaltung darf das nicht — sie darf löschen, und das ist
 * die ehrliche Form des Eingriffs.
 */
class IssueCommentPolicy
{
    /**
     * Einen Kommentar schreiben.
     *
     * Hängt am Fehler und nicht am Kommentar: es gibt ihn ja noch nicht.
     */
    public function create(User $user, Issue $issue): bool
    {
        $project = $issue->project;

        return $project !== null && $project->organization->hasMember($user);
    }

    public function update(User $user, IssueComment $comment): bool
    {
        return $comment->user_id !== null && $comment->user_id === $user->id;
    }

    /**
     * Löschen: der Schreibende — und die Verwaltung der Organisation.
     *
     * Das zweite ist die Möglichkeit, etwas wegzunehmen, das dort nicht stehen
     * darf (eine hineinkopierte Zugangskennung, eine Entgleisung), ohne dafür
     * in die Datenbank greifen zu müssen. Es ist bewusst auf Löschen
     * beschränkt: die Verwaltung kann einen Kommentar entfernen, aber niemandem
     * Worte in den Mund legen.
     */
    public function delete(User $user, IssueComment $comment): bool
    {
        if ($this->update($user, $comment)) {
            return true;
        }

        $organization = $comment->issue?->project?->organization;

        return $organization !== null
            && $organization->roleFor($user)?->atLeast(OrganizationRole::Admin) === true;
    }
}
