<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Rechte an Projekten. Ein Projekt gehört immer einer Organisation — die Rechte
 * ergeben sich aus der Rolle dort, nicht aus der Team-Zuordnung des Projekts.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->organization->hasMember($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Zuständige Teams zuordnen und wieder herausnehmen.
     */
    public function manageTeams(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Den Sicherheits-Token neu ziehen. Danach werden Meldungen mit dem alten
     * abgewiesen, deshalb dasselbe Recht wie für die übrigen Einstellungen.
     */
    public function rotateToken(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }
}
