<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\Filters\GlobalFilter;

/**
 * Wer eine gespeicherte Suche sehen, anlegen, ändern und löschen darf.
 *
 * **Sehen: der Ersteller — und bei Freigabe die ganze Organisation.** Eine
 * Freigabe ist die einzige Aussage, die eine Suche über ihre Sichtbarkeit
 * macht; ein Recht darüber hinaus („Verwaltung sieht alles") gäbe es hier
 * nicht zu gewinnen. Eine persönliche Ansicht ist kein Betriebsgeheimnis, aber
 * auch nichts, das jemand anderes in seiner Liste stehen haben will.
 *
 * **Ändern und löschen: nur der Ersteller.** Auch die Verwaltung nicht, und
 * anders als beim Kommentar ({@see IssueCommentPolicy}) gibt es hier nicht
 * einmal für das Löschen eine Ausnahme. Der Grund ist, dass eine gespeicherte
 * Suche nichts veröffentlicht: sie enthält keinen Text, den jemand anderes
 * lesen müsste, sondern eine Frage. Fällt eine freigegebene Ansicht unangenehm
 * auf, ist die Antwort ein Gespräch — nicht ein stiller Eingriff, nach dem der
 * Ersteller sie das nächste Mal vergeblich sucht.
 *
 * Auf die Rechteprüfung für die **Ergebnisse** hat all das keinen Einfluss: eine
 * Suche ist ein Text, und welche Fehler er zutage fördert, entscheidet nach wie
 * vor die Projektauswahl des Betrachters ({@see GlobalFilter}).
 * Eine freigegebene Suche über ein Projekt, in dem jemand nicht ist, liefert ihm
 * schlicht nichts.
 */
class SavedSearchPolicy
{
    public function view(User $user, SavedSearch $search): bool
    {
        if ($search->user_id === $user->id) {
            return true;
        }

        if (! $search->shared) {
            return false;
        }

        $organization = $search->organization;

        return $organization instanceof Organization && $organization->hasMember($user);
    }

    /**
     * Anlegen darf, wer Mitglied der Organisation ist.
     *
     * Hängt an der Organisation und nicht an der Suche: es gibt sie ja noch
     * nicht.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    public function update(User $user, SavedSearch $search): bool
    {
        return $search->user_id === $user->id;
    }

    public function delete(User $user, SavedSearch $search): bool
    {
        return $this->update($user, $search);
    }
}
