<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\User;
use App\Support\Filters\GlobalFilter;

/**
 * Wer ein Dashboard sehen, anlegen, ändern und löschen darf.
 *
 * Die Regeln sind die der gespeicherten Suche ({@see SavedSearchPolicy}), und
 * zwar absichtlich dieselben: beides sind festgehaltene Fragen, und wer sie in
 * der Fehlerliste versteht, soll sie hier nicht neu lernen müssen.
 *
 * **Sehen: der Ersteller — und bei Freigabe die ganze Organisation.**
 *
 * **Ändern und löschen: nur der Ersteller.** Auch die Verwaltung nicht. Ein
 * freigegebenes Dashboard, das unter demselben Namen morgen andere Kacheln
 * zeigt, wäre schlimmer als keines — es sieht vertraut aus. Wer ein fremdes als
 * Ausgangspunkt braucht, dupliziert es; danach ist es seines.
 *
 * **Auf die Daten hat all das keinen Einfluss.** Eine Kachel ist eine Frage;
 * welche Zahlen sie zutage fördert, entscheidet nach wie vor die Projektauswahl
 * des Betrachters ({@see GlobalFilter}). Ein freigegebenes Dashboard über einem
 * Projekt, in dem jemand nicht ist, liefert ihm schlicht nichts.
 */
class DashboardPolicy
{
    public function view(User $user, Dashboard $dashboard): bool
    {
        if ($dashboard->user_id === $user->id) {
            return true;
        }

        if (! $dashboard->shared) {
            return false;
        }

        $organization = $dashboard->organization;

        return $organization instanceof Organization && $organization->hasMember($user);
    }

    /**
     * Anlegen darf, wer Mitglied der Organisation ist — die Frage hängt an ihr,
     * weil es das Dashboard noch nicht gibt.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->user_id === $user->id;
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $this->update($user, $dashboard);
    }

    /**
     * Duplizieren darf, wer es sehen darf.
     *
     * Das ist der Ausweg aus „ändern darf nur der Ersteller": eine freigegebene
     * Sammlung soll sich als Ausgangspunkt benutzen lassen, ohne dass jemand am
     * Original schraubt. Angelegt wird dabei ein eigenes Dashboard — die Rechte
     * daran sind danach die des Duplizierenden.
     */
    public function duplicate(User $user, Dashboard $dashboard): bool
    {
        return $this->view($user, $dashboard);
    }
}
