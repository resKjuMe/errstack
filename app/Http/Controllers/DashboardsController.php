<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Http\Requests\GlobalFilterRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use App\Support\Dashboards\DashboardData;
use App\Support\Dashboards\DashboardTemplates;
use App\Support\Discover\DiscoverData;
use App\Support\Discover\DiscoverLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Dashboards: die Liste, ein einzelnes — und was man damit tut.
 *
 * **Die Seite liefert das Raster, nicht die Zahlen.** Sie kommt mit den Kacheln,
 * ihrer Anordnung und ihren Abfragen; die Zahlen holt jede Kachel danach selbst
 * ({@see DashboardWidgetDataController}). Das ist der Unterschied zwischen einem
 * Dashboard, das nach dem Klick sofort dasteht und sich füllt, und einem, das
 * so lange weiß bleibt, wie seine langsamste Abfrage dauert — bei zwanzig
 * Kacheln ist das kein Feinschliff, sondern der Unterschied zwischen benutzbar
 * und nicht.
 *
 * **Nicht zu verwechseln mit {@see DashboardController}**: der zeigt die eine
 * Übersichtsseite der Organisation (`uebersicht`). Hier geht es um die frei
 * zusammengestellten Sammlungen.
 */
class DashboardsController extends Controller
{
    /**
     * Die Liste: eigene und freigegebene Dashboards, zuletzt geändert zuerst.
     */
    public function index(GlobalFilterRequest $request): InertiaResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();

        // Der Filter wird angefordert, damit die Seite die globale Leiste
        // bekommt: die Liste selbst braucht ihn nicht, aber der Zeitraum, mit
        // dem man gleich ein Dashboard aufschlägt, wird hier eingestellt.
        $request->filter();

        $dashboards = Dashboard::query()
            ->visibleTo($user, $organization)
            ->withCount('widgets')
            ->with('user:id,name')
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('dashboards/Index', [
            'dashboards' => $dashboards
                ->map(fn (Dashboard $dashboard): array => DashboardData::summary($dashboard, $user))
                ->values()
                ->all(),
            'templates' => DashboardTemplates::options(),
            'limits' => ['perUser' => Dashboard::MAX_PER_USER],
            'createUrl' => route('dashboards.store', $organization),
        ]);
    }

    /**
     * Ein Dashboard mit seinen Kacheln — samt allem, was die Oberfläche zum
     * Bearbeiten braucht.
     */
    public function show(GlobalFilterRequest $request, Dashboard $dashboard): InertiaResponse
    {
        Gate::authorize('view', $dashboard);

        $filter = $request->filter();
        $user = $request->user();

        return Inertia::render('dashboards/Show', [
            'dashboard' => DashboardData::detail($dashboard->loadMissing('user:id,name'), $user),
            'widgets' => $dashboard->widgets
                ->map(fn (DashboardWidget $widget): array => DashboardData::widget($widget, $filter))
                ->values()
                ->all(),
            // Derselbe Katalog wie in der freien Auswertung: welche Quellen es
            // gibt, wonach sich gruppieren und worüber sich rechnen lässt. Eine
            // eigene Liste hier böte früher oder später etwas an, das der Motor
            // abweist.
            'catalog' => DiscoverData::catalog($filter->timezone, DiscoverLimits::fromConfig()),
            'grid' => DashboardData::grid(),
            'projectOptions' => DashboardData::projectOptions($filter),
            'environments' => $filter->availableEnvironments,
        ]);
    }

    /**
     * Ein neues Dashboard — leer oder aus einer Vorlage.
     */
    public function store(DashboardRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);

        Gate::authorize('create', [Dashboard::class, $organization]);

        $user = $request->user();

        // Die Grenze gilt beim Anlegen und nicht beim Ändern: ein bestehendes
        // Dashboard umzubenennen soll auch dann gehen, wenn die Grenze später
        // gesenkt wird.
        $count = Dashboard::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->count();

        if ($count >= Dashboard::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'name' => __('dashboards.errors.too_many', ['limit' => Dashboard::MAX_PER_USER]),
            ]);
        }

        $template = $request->template();

        $dashboard = DB::transaction(function () use ($request, $organization, $user, $template): Dashboard {
            if ($template !== null) {
                $dashboard = DashboardTemplates::create($template, $organization, $user, $request->name());

                // Beschreibung und Freigabe aus dem Formular gewinnen gegen die
                // der Vorlage: was jemand hingeschrieben hat, ist die genauere
                // Angabe.
                $dashboard->update([
                    'description' => $request->description() === '' ? $dashboard->description : $request->description(),
                    'shared' => $request->shared(),
                ]);

                return $dashboard;
            }

            return Dashboard::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'name' => $request->name(),
                'description' => $request->description(),
                'shared' => $request->shared(),
                'template' => null,
            ]);
        });

        return redirect()
            ->route('dashboards.show', [$organization, $dashboard])
            ->with('status', __('dashboards.flash.created'));
    }

    /**
     * Umbenennen, die Beschreibung nachschärfen, freigeben oder die Freigabe
     * zurücknehmen.
     */
    public function update(DashboardRequest $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('update', $dashboard);

        $dashboard->update([
            'name' => $request->name(),
            'description' => $request->description(),
            'shared' => $request->shared(),
        ]);

        return back()->with('status', __('dashboards.flash.updated'));
    }

    /**
     * Ein Duplikat — mit allen Kacheln, ihrer Anordnung und ihren Abfragen.
     *
     * **Das Duplikat gehört dem, der es angelegt hat**, und ist nicht
     * freigegeben. Das ist der Weg, ein fremdes Dashboard als Ausgangspunkt zu
     * benutzen, ohne am Original zu schrauben — und der Grund, warum „ändern
     * darf nur der Ersteller" keine Sackgasse ist.
     */
    public function duplicate(GlobalFilterRequest $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('duplicate', $dashboard);

        $organization = $dashboard->organization;

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException;
        }

        Gate::authorize('create', [Dashboard::class, $organization]);

        $user = $request->user();

        $copy = DB::transaction(function () use ($dashboard, $organization, $user): Dashboard {
            $copy = Dashboard::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'name' => self::copyName($dashboard, $user->id),
                'description' => $dashboard->description,
                // Eine Kopie ist erst einmal die eigene Angelegenheit: eine
                // stillschweigend mitkopierte Freigabe hätte das Duplikat für
                // die ganze Organisation sichtbar gemacht, ohne dass jemand das
                // gesagt hätte.
                'shared' => false,
                'template' => $dashboard->template,
            ]);

            foreach ($dashboard->widgets as $widget) {
                DashboardWidget::query()->create([
                    'dashboard_id' => $copy->id,
                    'title' => $widget->title,
                    'type' => $widget->type,
                    'query' => $widget->query,
                    'overrides' => $widget->overrides,
                    'x' => $widget->x,
                    'y' => $widget->y,
                    'width' => $widget->width,
                    'height' => $widget->height,
                ]);
            }

            return $copy;
        });

        return redirect()
            ->route('dashboards.show', [$organization, $copy])
            ->with('status', __('dashboards.flash.duplicated'));
    }

    public function destroy(GlobalFilterRequest $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('delete', $dashboard);

        $organization = $dashboard->organization;
        $dashboard->delete();

        return redirect()
            ->route('dashboards.index', $organization)
            ->with('status', __('dashboards.flash.deleted'));
    }

    /**
     * Der Name des Duplikats: „… (Kopie)", und bei Bedarf durchnummeriert.
     *
     * Der Name ist je Konto und Organisation eindeutig; ohne die Nummerierung
     * scheiterte das zweite Duplikat an einem Schlüssel, den niemand
     * hingeschrieben hat.
     */
    private static function copyName(Dashboard $dashboard, int $userId): string
    {
        $base = __('dashboards.copy_name', ['name' => $dashboard->name]);
        $base = mb_substr($base, 0, Dashboard::NAME_LIMIT);
        $name = $base;

        for ($suffix = 2; self::nameTaken($name, $dashboard->organization_id, $userId); $suffix++) {
            $tail = ' '.$suffix;
            $name = mb_substr($base, 0, Dashboard::NAME_LIMIT - mb_strlen($tail)).$tail;
        }

        return $name;
    }

    private static function nameTaken(string $name, int $organizationId, int $userId): bool
    {
        return Dashboard::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();
    }

    private function organization(GlobalFilterRequest $request): Organization
    {
        $organization = CurrentOrganization::for($request);

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException;
        }

        return $organization;
    }
}
