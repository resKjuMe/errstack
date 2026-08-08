<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Http\Requests\ProjectRequest;
use App\Http\Requests\ProjectSettingsRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Projekte anlegen, ansehen, einstellen und löschen. Jede Prüfung läuft über
 * die ProjectPolicy bzw. die OrganizationPolicy — hier steht keine
 * Rollenabfrage.
 */
class ProjectController extends Controller
{
    /**
     * Alle Projekte der aktiven Organisation. Gehört das Konto noch keiner an,
     * bleibt die Liste leer und die Seite verweist auf die Organisationen.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        return Inertia::render('projects/Index', ProjectData::index($user->resolveCurrentOrganization(), $user));
    }

    public function store(ProjectRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageProjects', $organization);

        $project = Project::createFor(
            $organization,
            (string) $request->validated('name'),
            Platform::from((string) $request->validated('platform')),
        );

        // Weiter in den Einrichtungs-Assistenten und nicht in die Einstellungen:
        // ein frisches Projekt hat nichts einzustellen, es hat etwas
        // anzuschließen. Wer nur ein Projekt anlegen wollte, ist von dort mit
        // einem Klick bei den Einstellungen — umgekehrt hätte niemand nach dem
        // Weg gesucht, den er noch nicht kennt.
        return redirect()
            ->route('projects.setup.index', [$organization, $project])
            ->with('status', __('projects.flash.created', ['name' => $project->name]));
    }

    public function show(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Show', ProjectData::detail($project, $request->user()));
    }

    public function update(ProjectSettingsRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return back()->with('status', __('projects.flash.updated'));
    }

    public function destroy(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $name = $project->name;
        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('status', __('projects.flash.deleted', ['name' => $name]));
    }
}
