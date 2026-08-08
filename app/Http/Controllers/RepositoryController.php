<?php

namespace App\Http\Controllers;

use App\Http\Requests\RepositoryRequest;
use App\Models\Organization;
use App\Models\Repository;
use App\Support\Formats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die verbundenen Repositories einer Organisation.
 *
 * **Verbinden heißt hier: eintragen.** Solange es keine Anbindung gibt (X1/X2),
 * holt niemand von selbst Commits ab — eine Bauumgebung übergibt sie über die
 * Schnittstelle, unter genau dem Namen, der hier steht. Die Seite ist damit
 * zweierlei: die Stelle, an der man diesen Namen festlegt, und die Übersicht
 * darüber, woher der Code einer Organisation kommt.
 *
 * Ein Repository entsteht auch von selbst, wenn eine Übergabe einen unbekannten
 * Namen mitbringt (siehe {@see Repository::forName()}) — die Seite ist der Weg,
 * ihm danach seine Adresse zu geben oder eine Fehleingabe wieder loszuwerden.
 */
class RepositoryController extends Controller
{
    /**
     * Ansehen darf jedes Mitglied: die Liste sagt nur, aus welchen
     * Repositories die Auslieferungen dieser Organisation stammen — dieselbe
     * Auskunft, die auf jeder Versionsseite ohnehin steht.
     */
    public function index(Organization $organization): InertiaResponse
    {
        Gate::authorize('view', $organization);

        $repositories = $organization->repositories()
            ->withCount('commits')
            ->orderBy('name')
            ->get();

        return Inertia::render('repositories/Index', [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'canManage' => Gate::allows('manageRepositories', $organization),
            'repositories' => $repositories->map(fn (Repository $repository): array => [
                'id' => $repository->id,
                'name' => $repository->name,
                'url' => $repository->url,
                'provider' => $repository->provider,
                // Roh und geschrieben, wie überall: wie eine Zahl aussieht,
                // entscheidet die Sprache, und die kennt der Server.
                'commitCount' => (int) $repository->getAttribute('commits_count'),
                'commitCountLabel' => Formats::number((int) $repository->getAttribute('commits_count')),
                'deleteHref' => route('repositories.destroy', $repository),
            ])->all(),
            'storeHref' => route('organizations.repositories.store', $organization),
        ]);
    }

    public function store(RepositoryRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageRepositories', $organization);

        $validated = $request->validated();
        $name = Repository::normalizeName($validated['name']);

        if ($name === null) {
            // Ein Name, von dem nach dem Vereinheitlichen nichts übrig bleibt,
            // hat die Prüfung oben bestanden und ist trotzdem keiner.
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('repositories.fields.name')]),
            ]);
        }

        if ($organization->repositories()->where('name', $name)->exists()) {
            // Ein zweites Repository desselben Namens gibt es nicht — das hält
            // schon der eindeutige Index fest. Die Meldung steht hier, damit
            // daraus ein Hinweis am Feld wird und keine Fehlerseite.
            throw ValidationException::withMessages([
                'name' => __('repositories.validation.duplicate'),
            ]);
        }

        $repository = new Repository([
            'name' => $name,
            'provider' => Repository::PROVIDER_MANUAL,
            'url' => $validated['url'] ?? null,
        ]);

        $repository->organization_id = $organization->id;
        $repository->save();

        return back()->with('status', __('repositories.flash.connected'));
    }

    /**
     * Ein Repository lösen.
     *
     * Es nimmt seine Commits mit, und damit den Inhalt jeder Auslieferung, die
     * aus ihm bestand. Die Auslieferungen selbst bleiben — sie sind aus den
     * Meldungen entstanden und nicht aus dem Repository.
     */
    public function destroy(Repository $repository): RedirectResponse
    {
        Gate::authorize('manageRepositories', $repository->organization);

        $repository->delete();

        return back()->with('status', __('repositories.flash.disconnected'));
    }
}
