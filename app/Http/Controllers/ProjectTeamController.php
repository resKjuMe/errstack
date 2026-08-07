<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Zuständige Teams eines Projekts. Die Liste wird als Ganzes gesetzt, nicht
 * einzeln zugeordnet — die Oberfläche zeigt alle Teams der Organisation mit
 * Häkchen.
 */
class ProjectTeamController extends Controller
{
    public function update(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageTeams', $project);

        $validated = $request->validate([
            'teams' => ['array'],
            // Ein Projekt reicht nie über seine Organisation hinaus: fremde
            // Teams gibt es an dieser Stelle nicht.
            'teams.*' => [
                'integer',
                Rule::exists('teams', 'id')->where('organization_id', $organization->id),
            ],
        ]);

        $project->teams()->sync($validated['teams'] ?? []);

        return back()->with('status', __('projects.flash.teams_updated'));
    }
}
