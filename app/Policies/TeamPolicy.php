<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Rechte an Teams. Ein Team gehört immer einer Organisation — die Rechte
 * ergeben sich aus der Rolle dort, nicht aus der Team-Zugehörigkeit.
 */
class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->organization->hasMember($user);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->can('manageTeams', $team->organization);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->can('manageTeams', $team->organization);
    }

    /**
     * Mitglieder zuordnen und wieder herausnehmen.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        return $user->can('manageTeams', $team->organization);
    }
}
