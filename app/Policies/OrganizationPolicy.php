<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/**
 * Zentrale Rechteprüfung für Organisationen. Die Controller fragen nie selbst
 * nach der Rolle — jede Entscheidung fällt hier.
 */
class OrganizationPolicy
{
    /**
     * Sehen darf jedes Mitglied, unabhängig von der Rolle. Wer nicht Mitglied
     * ist, sieht die Organisation gar nicht.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    /**
     * Eine eigene Organisation darf jedes bestätigte Konto anlegen.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Stammdaten ändern (z. B. umbenennen) — ab der Verwaltung.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Admin);
    }

    /**
     * Endgültig löschen darf nur der Besitzer.
     */
    public function delete(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Owner);
    }

    /**
     * Mitglieder einladen und Einladungen zurückziehen.
     */
    public function invite(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Admin);
    }

    /**
     * Teams anlegen, umbenennen, löschen und besetzen.
     */
    public function manageTeams(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Admin);
    }

    /**
     * Projekte anlegen, einstellen und löschen.
     */
    public function manageProjects(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Admin);
    }

    private function atLeast(User $user, Organization $organization, OrganizationRole $minimum): bool
    {
        return $organization->roleFor($user)?->atLeast($minimum) === true;
    }
}
