<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedQueryRequest;
use App\Http\Requests\SavedQueryWidgetRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Organization;
use App\Models\SavedQuery;
use App\Support\CurrentOrganization;
use App\Support\Dashboards\DashboardLayout;
use App\Support\Discover\SavedQueryData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Verwaltung der gespeicherten Auswertungen: anlegen, umbenennen,
 * freigeben, duplizieren, löschen — und eine davon als Kachel übernehmen.
 *
 * **Es gibt keine eigene Seite dafür.** Alle Aktionen kehren dorthin zurück, wo
 * sie ausgelöst wurden: zur Auswertung. Eine Verwaltungsseite hätte bedeutet,
 * dass man zum Speichern einer Auswertung die Auswertung verlässt — und dabei
 * genau den Zustand aus den Augen verliert, den man festhalten wollte. Die
 * Ausnahme ist das Übernehmen: es endet auf dem Dashboard, weil die Kachel dort
 * gelandet ist und man sie dort sehen will.
 *
 * **Geöffnet wird eine Auswertung nicht hier.** Sie besteht aus einer Frage und
 * einem Ausschnitt, und beides steht in der Adresszeile der Auswertungsseite;
 * die Adresse dafür baut {@see SavedQueryData::href()}. Eine Route „öffnen", die
 * auf die Seite weiterleitet, wäre ein Umweg, an dessen Ende dieselbe Adresse
 * steht — und ein zweiter Ort, an dem entschieden wird, was eine gespeicherte
 * Auswertung mit dem Zeitraum macht.
 */
class SavedQueryController extends Controller
{
    public function store(SavedQueryRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);

        Gate::authorize('create', [SavedQuery::class, $organization]);

        $user = $request->user();

        // Die Grenze wird beim Anlegen geprüft und nicht beim Ändern: eine
        // bestehende Auswertung umzubenennen soll auch dann gehen, wenn die
        // Grenze später gesenkt wird.
        $this->ensureRoom($organization, $user->id);

        SavedQuery::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'name' => $request->name(),
            'description' => $request->description(),
            'query' => $request->discoverQuery()->toArray(),
            'filters' => self::filters($request),
            'shared' => $request->shared(),
        ]);

        return back()->with('status', __('discover.saved.flash.created'));
    }

    /**
     * Umbenennen, die Frage nachschärfen, freigeben oder die Freigabe
     * zurücknehmen — alles derselbe Aufruf.
     *
     * Wird die Freigabe zurückgenommen, verschwindet die Auswertung bei allen
     * anderen aus der Leiste. Kacheln, die aus ihr entstanden sind, bleiben
     * stehen: sie sind eine eigene Kopie der Frage und keine Verknüpfung — die
     * Begründung steht bei {@see self::widget()}.
     */
    public function update(SavedQueryRequest $request, SavedQuery $savedQuery): RedirectResponse
    {
        Gate::authorize('update', $savedQuery);

        $savedQuery->update([
            'name' => $request->name(),
            'description' => $request->description(),
            'query' => $request->discoverQuery()->toArray(),
            'filters' => self::filters($request),
            'shared' => $request->shared(),
        ]);

        return back()->with('status', __('discover.saved.flash.updated'));
    }

    /**
     * Eine eigene Kopie — der Weg, eine fremde Auswertung als Ausgangspunkt zu
     * benutzen, ohne am Original zu schrauben.
     */
    public function duplicate(Request $request, SavedQuery $savedQuery): RedirectResponse
    {
        Gate::authorize('duplicate', $savedQuery);

        $organization = $savedQuery->organization;

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException;
        }

        Gate::authorize('create', [SavedQuery::class, $organization]);

        $user = $request->user();

        $this->ensureRoom($organization, $user->id);

        SavedQuery::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'name' => self::copyName($savedQuery, $user->id),
            'description' => $savedQuery->description,
            'query' => $savedQuery->query,
            'filters' => $savedQuery->filters,
            // Eine Kopie ist erst einmal die eigene Angelegenheit: eine
            // stillschweigend mitkopierte Freigabe hätte das Duplikat für die
            // ganze Organisation sichtbar gemacht, ohne dass jemand das gesagt
            // hätte — dieselbe Regel wie beim duplizierten Dashboard.
            'shared' => false,
        ]);

        return back()->with('status', __('discover.saved.flash.duplicated'));
    }

    public function destroy(SavedQuery $savedQuery): RedirectResponse
    {
        Gate::authorize('delete', $savedQuery);

        $savedQuery->delete();

        return back()->with('status', __('discover.saved.flash.deleted'));
    }

    /**
     * Diese Auswertung als Kachel auf ein Dashboard.
     *
     * **Die Kachel bekommt eine Kopie der Frage, keine Verknüpfung.** Ein
     * Verweis auf die gespeicherte Auswertung hätte bedeutet, dass ein Dashboard
     * sich ändert, weil jemand anderes seine Auswertung nachgeschärft hat — und
     * dass es kaputtgeht, wenn er sie löscht. Eine Kachel ist eine
     * festgehaltene Frage; wer sie ändern will, ändert die Kachel.
     *
     * **Der gespeicherte Ausschnitt geht ausdrücklich nicht mit.** Auf dem
     * Dashboard gilt dessen Filterleiste — das ist deren ganzer Zweck (siehe
     * {@see App\Support\Dashboards\WidgetOverrides}). Eine übernommene Kachel,
     * die auf „letzte 24 Stunden" festgenagelt wäre, würde sich beim Umstellen
     * des Zeitraums als Einzige nicht rühren; bei einem Dashboard aus zehn
     * übernommenen Auswertungen wäre die Leiste dort wirkungslos.
     */
    public function widget(SavedQueryWidgetRequest $request, SavedQuery $savedQuery): RedirectResponse
    {
        Gate::authorize('view', $savedQuery);

        $organization = $savedQuery->organization;

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException;
        }

        $dashboard = $request->dashboard($savedQuery);

        Gate::authorize('update', $dashboard);

        if (! $dashboard->hasRoomForWidget()) {
            throw ValidationException::withMessages([
                'dashboard' => __('dashboards.errors.too_many_widgets', ['limit' => DashboardLayout::MAX_WIDGETS]),
            ]);
        }

        DashboardWidget::query()->create([
            'dashboard_id' => $dashboard->id,
            'title' => $request->title($savedQuery),
            'type' => $request->type(),
            'query' => $savedQuery->discoverQuery()->toArray(),
            'overrides' => null,
            // Unter allem, was schon da ist — dort, wo man eine neue Kachel
            // sucht.
            ...DashboardLayout::normalize(
                0,
                DashboardLayout::nextRow($dashboard->widgets),
                DashboardLayout::DEFAULT_WIDTH,
                DashboardLayout::DEFAULT_HEIGHT,
            ),
        ]);

        // Die Liste der Dashboards sortiert nach „zuletzt geändert", und eine
        // neue Kachel ist eine Änderung daran.
        $dashboard->touch();

        return redirect()
            ->route('dashboards.show', [$organization, $dashboard])
            ->with('status', __('discover.saved.flash.widget_created', ['dashboard' => $dashboard->name]));
    }

    /**
     * Leere Angaben werden zu `null` und nicht zu einem leeren Objekt: „die
     * Auswertung sagt nichts zum Ausschnitt" und „sie sagt nichts, aber
     * ausdrücklich" wären sonst zwei Zustände mit derselben Wirkung.
     *
     * @return array<string, string|null>|null
     */
    private static function filters(SavedQueryRequest $request): ?array
    {
        $filters = $request->savedFilters();

        return $filters->isEmpty() ? null : $filters->toArray();
    }

    /**
     * Der Name einer Kopie — „… (Kopie)", und bei Bedarf durchnummeriert.
     */
    private static function copyName(SavedQuery $saved, int $userId): string
    {
        $base = __('discover.saved.copy_name', ['name' => $saved->name]);
        $base = mb_substr($base, 0, SavedQuery::NAME_LIMIT);
        $name = $base;

        for ($suffix = 2; self::nameTaken($name, $saved->organization_id, $userId); $suffix++) {
            $tail = ' '.$suffix;
            $name = mb_substr($base, 0, SavedQuery::NAME_LIMIT - mb_strlen($tail)).$tail;
        }

        return $name;
    }

    private static function nameTaken(string $name, int $organizationId, int $userId): bool
    {
        return SavedQuery::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();
    }

    /**
     * Ist noch Platz im eigenen Bestand?
     *
     * Die Meldung hängt am Namensfeld, weil das Formular nur dieses eine hat,
     * an dem sie stehen kann — dieselbe Stelle wie bei der gespeicherten Suche.
     */
    private function ensureRoom(Organization $organization, int $userId): void
    {
        $count = SavedQuery::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organization->id)
            ->count();

        if ($count >= SavedQuery::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'name' => __('discover.saved.errors.too_many', ['limit' => SavedQuery::MAX_PER_USER]),
            ]);
        }
    }

    /**
     * Die Organisation, in der gespeichert wird — die aus der Adresse.
     *
     * Sie steht nicht im Rumpf, und das ist Absicht: die Auswertung gehört zu
     * der Organisation, unter deren Adresse sie aufgerufen wurde, und eine
     * Auswertung, die in einer anderen landet, wäre dort unsichtbar und hier
     * verschwunden.
     */
    private function organization(Request $request): Organization
    {
        $organization = CurrentOrganization::for($request);

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException;
        }

        return $organization;
    }
}
