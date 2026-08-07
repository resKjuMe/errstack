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
     * Client-Schlüssel anlegen, abschalten, neu ziehen und löschen. Wer das
     * darf, bekommt auch die DSN im Klartext zu sehen — sie ist der Zugang zur
     * Datenaufnahme, deshalb dasselbe Recht wie für die übrigen Einstellungen.
     */
    public function manageKeys(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }
}
