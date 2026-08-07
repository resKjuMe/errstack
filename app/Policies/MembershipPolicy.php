<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\User;

/**
 * Rechte an einer einzelnen Mitgliedschaft: Rolle ändern und Mitglied
 * entfernen. Beides hat Fallstricke, die hier zentral geregelt sind — sonst
 * ließe sich der letzte Besitzer entfernen und niemand käme mehr an die
 * Organisation heran.
 */
class MembershipPolicy
{
    /**
     * Rolle eines Mitglieds ändern. Die neue Rolle gehört zur Entscheidung
     * dazu: die Besitzer-Rolle vergibt nur ein Besitzer.
     */
    public function updateRole(User $user, Membership $membership, OrganizationRole $role): bool
    {
        $actor = $membership->organization->roleFor($user);

        if ($actor === null || ! $actor->atLeast(OrganizationRole::Admin)) {
            return false;
        }

        // Die eigene Rolle ändert niemand selbst — sonst könnte sich eine
        // Verwaltung zum Besitzer machen.
        if ($membership->user_id === $user->id) {
            return false;
        }

        // Einen Besitzer anfassen oder zum Besitzer machen darf nur ein Besitzer.
        if (($membership->role === OrganizationRole::Owner || $role === OrganizationRole::Owner)
            && $actor !== OrganizationRole::Owner) {
            return false;
        }

        return ! $this->wouldDropLastOwner($membership, $role);
    }

    /**
     * Mitglied entfernen. Das eigene Konto darf jeder herausnehmen (Organisation
     * verlassen), fremde erst ab der Verwaltung.
     */
    public function delete(User $user, Membership $membership): bool
    {
        $actor = $membership->organization->roleFor($user);

        if ($actor === null) {
            return false;
        }

        $isSelf = $membership->user_id === $user->id;

        if (! $isSelf && ! $actor->atLeast(OrganizationRole::Admin)) {
            return false;
        }

        // Einen fremden Besitzer entfernt nur ein Besitzer.
        if (! $isSelf && $membership->role === OrganizationRole::Owner && $actor !== OrganizationRole::Owner) {
            return false;
        }

        return ! $this->wouldDropLastOwner($membership, null);
    }

    /**
     * Bliebe die Organisation ohne Besitzer zurück? `null` als Zielrolle steht
     * für „Mitgliedschaft fällt ganz weg".
     */
    private function wouldDropLastOwner(Membership $membership, ?OrganizationRole $role): bool
    {
        if ($membership->role !== OrganizationRole::Owner || $role === OrganizationRole::Owner) {
            return false;
        }

        return $membership->organization->memberships()
            ->where('role', OrganizationRole::Owner->value)
            ->count() <= 1;
    }
}
