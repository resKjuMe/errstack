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
     * Mitglieder einladen und Einladungen zurückziehen. Mit `$role` wird
     * zugleich geprüft, ob diese Rolle vergeben werden darf: die Besitzer-Rolle
     * vergibt nur ein Besitzer — sonst könnte sich eine Verwaltung über eine
     * Einladung an sich selbst zum Besitzer machen.
     */
    public function invite(User $user, Organization $organization, ?OrganizationRole $role = null): bool
    {
        if (! $this->atLeast($user, $organization, OrganizationRole::Admin)) {
            return false;
        }

        return $role !== OrganizationRole::Owner
            || $organization->roleFor($user) === OrganizationRole::Owner;
    }

    /**
     * Teams anlegen, umbenennen, löschen und besetzen.
     */
    public function manageTeams(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Admin);
    }

    /**
     * Benachrichtigungswege einrichten, ändern, testen und löschen. Ab der
     * Verwaltung: in den Kanälen stecken Zugangsdaten, und wer sie ändert,
     * bestimmt, wohin künftig jede Meldung geht.
     */
    public function manageNotifications(User $user, Organization $organization): bool
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

    /**
     * Repositories verbinden und wieder lösen (R2).
     *
     * Ab der Verwaltung, und aus demselben Grund wie bei den Projekten: ein
     * Repository ist die Herkunft des Codes einer Organisation, und wer es löst,
     * nimmt allen Auslieferungen ihre Commits. Ansehen darf die Liste dagegen
     * jedes Mitglied — sie steht auch auf jeder Versionsseite.
     */
    public function manageRepositories(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Admin);
    }

    /**
     * Das Änderungsprotokoll einsehen und ausgeben. Es zeigt, wer wann was
     * getan hat, samt IP-Adresse — das geht nur die Verwaltung etwas an, nicht
     * jedes Mitglied.
     */
    public function viewAuditLog(User $user, Organization $organization): bool
    {
        return $this->atLeast($user, $organization, OrganizationRole::Admin);
    }

    private function atLeast(User $user, Organization $organization, OrganizationRole $minimum): bool
    {
        return $organization->roleFor($user)?->atLeast($minimum) === true;
    }
}
