<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Sicherheits-Token eines Projekts neu ziehen.
 */
class ProjectTokenController extends Controller
{
    public function update(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('rotateToken', $project);

        $project->rotateToken();

        return back()->with('status', 'Neuer Token erzeugt — der bisherige gilt nicht mehr.');
    }
}
