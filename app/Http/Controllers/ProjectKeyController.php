<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectKeyRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Support\ProjectKeyData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Client-Schlüssel eines Projekts: die DSNs, mit denen sich Anwendungen
 * melden. Jede Prüfung läuft über die ProjectPolicy — hier steht keine
 * Rollenabfrage.
 */
class ProjectKeyController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        // Die DSN ist der Zugang zur Datenaufnahme. Wer die Schlüssel nicht
        // verwalten darf, soll sie deshalb auch nicht ablesen können — anders
        // als bei den übrigen Einstellungen gibt es hier keine Nur-Lese-Sicht.
        Gate::authorize('manageKeys', $project);

        return Inertia::render('projects/Keys', ProjectKeyData::index($project));
    }

    public function store(ProjectKeyRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageKeys', $project);

        $key = ProjectKey::createFor(
            $project,
            (string) $request->validated('name'),
            $this->rateLimit($request),
        );

        return back()->with('status', "Schlüssel „{$key->name}“ angelegt.");
    }

    public function update(ProjectKeyRequest $request, Organization $organization, Project $project, ProjectKey $key): RedirectResponse
    {
        Gate::authorize('manageKeys', $project);

        $key->update([
            'name' => (string) $request->validated('name'),
            'rate_limit_per_minute' => $this->rateLimit($request),
        ]);

        return back()->with('status', 'Schlüssel gespeichert.');
    }

    /**
     * An- und abschalten. Ein abgeschalteter Schlüssel bleibt stehen, wird bei
     * der Datenaufnahme aber abgewiesen — der Weg, eine Anwendung
     * stillzulegen, ohne ihre Zugangsdaten zu verlieren.
     */
    public function toggle(Organization $organization, Project $project, ProjectKey $key): RedirectResponse
    {
        Gate::authorize('manageKeys', $project);

        $key->update(['active' => ! $key->active]);

        return back()->with('status', $key->active
            ? "Schlüssel „{$key->name}“ ist wieder aktiv."
            : "Schlüssel „{$key->name}“ ist abgeschaltet — Meldungen damit werden abgewiesen.");
    }

    public function rotate(Organization $organization, Project $project, ProjectKey $key): RedirectResponse
    {
        Gate::authorize('manageKeys', $project);

        $key->rotate();

        return back()->with('status', 'Neue DSN erzeugt — die bisherige gilt nicht mehr.');
    }

    public function destroy(Organization $organization, Project $project, ProjectKey $key): RedirectResponse
    {
        Gate::authorize('manageKeys', $project);

        // Ohne Schlüssel hätte das Projekt keine Adresse mehr, an die etwas
        // gemeldet werden könnte. Wer ihn loswerden will, schaltet ihn ab.
        if ($project->keys()->count() === 1) {
            return back()->withErrors([
                'key' => 'Der letzte Schlüssel lässt sich nicht löschen — schalte ihn stattdessen ab.',
            ]);
        }

        $name = $key->name;
        $key->delete();

        return back()->with('status', "Schlüssel „{$name}“ gelöscht.");
    }

    private function rateLimit(ProjectKeyRequest $request): ?int
    {
        $value = $request->validated('rate_limit_per_minute');

        return $value === null ? null : (int) $value;
    }
}
