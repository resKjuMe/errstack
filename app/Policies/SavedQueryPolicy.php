<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\SavedQuery;
use App\Models\User;
use App\Support\Filters\GlobalFilter;

/**
 * Wer eine gespeicherte Auswertung sehen, anlegen, ändern, duplizieren und
 * löschen darf.
 *
 * **Sehen: der Ersteller — und bei Freigabe die ganze Organisation.** Dieselbe
 * Regel wie bei der gespeicherten Suche ({@see SavedSearchPolicy}) und beim
 * Dashboard ({@see DashboardPolicy}), und aus demselben Grund: eine Freigabe ist
 * die einzige Aussage, die eine Auswertung über ihre Sichtbarkeit macht.
 *
 * **Ändern und löschen: nur der Ersteller** — auch die Verwaltung nicht. Eine
 * freigegebene Auswertung, die unter fremdem Namen etwas anderes zeigt als
 * gestern, ist schlimmer als keine: sie sieht vertraut aus.
 *
 * **Duplizieren darf, wer sie sehen darf.** Das ist der Ausweg aus der Regel
 * darüber: wer eine freigegebene Auswertung als Ausgangspunkt braucht, nimmt
 * eine eigene Kopie und schraubt nicht am Original.
 *
 * Auf die Rechteprüfung für die **Zahlen** hat all das keinen Einfluss: eine
 * Auswertung ist eine Frage, und über welche Daten sie gestellt wird,
 * entscheidet nach wie vor die Projektauswahl des Betrachters
 * ({@see GlobalFilter}). Eine freigegebene Auswertung über ein Projekt, in dem
 * jemand nicht ist, liefert ihm schlicht nichts.
 */
class SavedQueryPolicy
{
    public function view(User $user, SavedQuery $query): bool
    {
        if ($query->user_id === $user->id) {
            return true;
        }

        if (! $query->shared) {
            return false;
        }

        $organization = $query->organization;

        return $organization instanceof Organization && $organization->hasMember($user);
    }

    /**
     * Anlegen darf, wer Mitglied der Organisation ist.
     *
     * Hängt an der Organisation und nicht an der Auswertung: es gibt sie ja
     * noch nicht.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    public function update(User $user, SavedQuery $query): bool
    {
        return $query->user_id === $user->id;
    }

    public function delete(User $user, SavedQuery $query): bool
    {
        return $this->update($user, $query);
    }

    public function duplicate(User $user, SavedQuery $query): bool
    {
        return $this->view($user, $query);
    }
}
