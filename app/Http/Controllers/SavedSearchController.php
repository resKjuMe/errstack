<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedSearchDefaultRequest;
use App\Http\Requests\SavedSearchRequest;
use App\Models\Organization;
use App\Models\SavedSearch;
use App\Models\SavedSearchDefault;
use App\Support\Issues\IssueViews;
use App\Support\Issues\SavedSearchData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Verwaltung der gespeicherten Suchen: anlegen, umbenennen, freigeben,
 * löschen — und festlegen, mit welcher Suche ein Projekt aufgeht.
 *
 * **Es gibt keine eigene Seite dafür.** Alle Aktionen kehren dorthin zurück,
 * wo sie ausgelöst wurden: zur Fehlerliste. Eine Verwaltungsseite hätte
 * bedeutet, dass man zum Speichern einer Suche die Suche verlässt — und dabei
 * genau den Zustand aus den Augen verliert, den man festhalten wollte.
 *
 * **Angewendet wird eine Suche nicht hier.** Sie ist ein Suchausdruck und eine
 * Sortierung, und beides steht in der Adresszeile der Fehlerliste; die Adresse
 * dafür baut {@see IssueViews::href()}. Eine Route
 * „Suche anwenden", die auf die Liste weiterleitet, wäre ein Umweg, an dessen
 * Ende dieselbe Adresse steht — und ein zweiter Ort, an dem entschieden wird,
 * was eine Suche mit dem Zeitraum macht.
 */
class SavedSearchController extends Controller
{
    public function store(SavedSearchRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);

        Gate::authorize('create', [SavedSearch::class, $organization]);

        $user = $request->user();

        // Die Grenze wird beim Anlegen geprüft und nicht beim Ändern: eine
        // bestehende Suche umzubenennen soll auch dann gehen, wenn die Grenze
        // später gesenkt wird.
        $count = SavedSearch::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->count();

        if ($count >= SavedSearch::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'name' => __('issues.saved.errors.too_many', ['limit' => SavedSearch::MAX_PER_USER]),
            ]);
        }

        SavedSearch::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'name' => $request->name(),
            'query' => $request->expression(),
            'sort' => $request->sort(),
            'shared' => $request->shared(),
        ]);

        return back()->with('status', __('issues.saved.flash.created'));
    }

    /**
     * Umbenennen, den Ausdruck nachschärfen, freigeben oder die Freigabe
     * zurücknehmen — alles derselbe Aufruf.
     *
     * Wird die Freigabe zurückgenommen, verschwindet die Suche bei allen
     * anderen aus der Liste. Ihre Einträge unter „Standard für dieses Projekt"
     * werden dabei **nicht** gelöscht: sie greifen nur noch nicht mehr
     * ({@see SavedSearchData::defaultSearch()}), und wird
     * die Freigabe wieder gesetzt, steht der Einstieg wieder da. Ein stilles
     * Aufräumen wäre die Entscheidung, dass eine versehentlich entfernte
     * Freigabe die Einstellungen der Kollegen kostet.
     */
    public function update(SavedSearchRequest $request, SavedSearch $search): RedirectResponse
    {
        Gate::authorize('update', $search);

        $search->update([
            'name' => $request->name(),
            'query' => $request->expression(),
            'sort' => $request->sort(),
            'shared' => $request->shared(),
        ]);

        return back()->with('status', __('issues.saved.flash.updated'));
    }

    public function destroy(SavedSearch $search): RedirectResponse
    {
        Gate::authorize('delete', $search);

        $search->delete();

        return back()->with('status', __('issues.saved.flash.deleted'));
    }

    /**
     * Diese Suche wird der Einstieg des Betrachters in dieses Projekt.
     *
     * Sehen muss er sie dürfen — mehr nicht: eine freigegebene Suche eines
     * Kollegen zu seinem Einstieg zu machen, ist genau der Zweck der Freigabe.
     */
    public function setDefault(SavedSearchDefaultRequest $request, SavedSearch $search): RedirectResponse
    {
        Gate::authorize('view', $search);

        $project = $request->project();

        if ($project->organization_id !== $search->organization_id) {
            throw new NotFoundHttpException;
        }

        SavedSearchDefault::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'project_id' => $project->id],
            ['saved_search_id' => $search->id],
        );

        return back()->with('status', __('issues.saved.flash.default_set', ['project' => $project->name]));
    }

    /**
     * Zurück zur gewöhnlichen Fehlerliste.
     *
     * Der Eintrag wird nur entfernt, wenn **diese** Suche der Einstieg ist —
     * sonst hätte ein veralteter Knopf im Browser den Einstieg gelöscht, den
     * inzwischen eine andere Suche innehat.
     */
    public function clearDefault(SavedSearchDefaultRequest $request, SavedSearch $search): RedirectResponse
    {
        Gate::authorize('view', $search);

        SavedSearchDefault::query()
            ->where('user_id', $request->user()->id)
            ->where('project_id', $request->project()->id)
            ->where('saved_search_id', $search->id)
            ->delete();

        return back()->with('status', __('issues.saved.flash.default_cleared'));
    }

    /**
     * Die Organisation, in der gespeichert wird — die aktive des Betrachters.
     *
     * Sie steht nicht in der Anfrage, und das ist Absicht: die Fehlerliste
     * gehört zur aktiven Organisation, und eine Suche, die in einer anderen
     * landet, wäre dort unsichtbar und hier verschwunden.
     */
    private function organization(SavedSearchRequest $request): Organization
    {
        $organization = $request->user()->resolveCurrentOrganization();

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException;
        }

        return $organization;
    }
}
