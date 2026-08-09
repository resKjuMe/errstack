<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\OrganizationRole;
use App\Http\Requests\OrganizationRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\AuditLog;
use App\Support\Filters\FilterQuery;
use App\Support\OrganizationData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Organisationen anlegen, ansehen, umbenennen und löschen. Jede Prüfung läuft
 * über die OrganizationPolicy — hier steht keine Rollenabfrage.
 */
class OrganizationController extends Controller
{
    /**
     * Alle Organisationen dieses Kontos.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $memberships = $user->memberships()
            ->with('organization')
            ->get()
            ->sortBy(fn (Membership $membership): string => (string) $membership->organization->name)
            ->values();

        return Inertia::render('organizations/Index', [
            'organizations' => $memberships->map(fn (Membership $membership): array => [
                'slug' => $membership->organization->slug,
                'name' => $membership->organization->name,
                'role' => $membership->role->value,
                'roleLabel' => $membership->role->label(),
                'isCurrent' => $membership->organization_id === $user->current_organization_id,
                'href' => route('organizations.show', $membership->organization),
            ])->all(),
        ]);
    }

    public function store(OrganizationRequest $request): RedirectResponse
    {
        Gate::authorize('create', Organization::class);

        $user = $request->user();

        // Anlegen und Besitz übernehmen gehören zusammen: eine Organisation
        // ohne Besitzer wäre von niemandem mehr zu verwalten.
        $organization = DB::transaction(function () use ($request, $user): Organization {
            $organization = Organization::createNamed((string) $request->validated('name'));
            $organization->setRole($user, OrganizationRole::Owner);

            AuditLog::record(
                AuditAction::OrganizationCreated,
                $organization,
                subject: $organization,
                subjectLabel: $organization->name,
                changes: AuditLog::change('name', null, $organization->name),
            );

            return $organization;
        });

        $user->switchOrganization($organization);

        return redirect()
            ->route('organizations.show', $organization)
            ->with('status', __('organizations.flash.created', ['name' => $organization->name]));
    }

    public function show(Request $request, Organization $organization): InertiaResponse
    {
        Gate::authorize('view', $organization);

        return Inertia::render('organizations/Show', OrganizationData::detail($organization, $request->user()));
    }

    public function update(OrganizationRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $before = $organization->name;

        $organization->update($request->validated());

        // Nur protokollieren, wenn sich wirklich etwas geändert hat — ein
        // erneutes Speichern desselben Namens ist keine Änderung.
        if ($organization->name !== $before) {
            AuditLog::record(
                AuditAction::OrganizationUpdated,
                $organization,
                subject: $organization,
                subjectLabel: $organization->name,
                changes: AuditLog::change('name', $before, $organization->name),
            );
        }

        return back()->with('status', __('organizations.flash.updated'));
    }

    public function destroy(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('delete', $organization);

        $name = $organization->name;

        // Hier entsteht bewusst kein Protokolleintrag: das Protokoll gehört der
        // Organisation und verschwindet mit ihr. Ein Eintrag wäre im selben
        // Aufruf wieder weg — wer das Löschen einer ganzen Organisation
        // nachhalten will, braucht ein Protokoll oberhalb der Organisation.
        $organization->delete();

        // Danach steht die Wahl der Organisation neu an.
        $request->user()->resolveCurrentOrganization();

        return redirect()
            ->route('organizations.index')
            ->with('status', __('organizations.flash.deleted', ['name' => $name]));
    }

    /**
     * Zwischen den eigenen Organisationen wechseln.
     *
     * Der Wechsel führt dorthin, wo man gerade war — nur in der neuen
     * Organisation. Seit die Fachseiten die Organisation in der Adresse tragen
     * (U5), wäre ein schlichtes `back()` das Gegenteil davon: es führte auf die
     * Adresse der **alten** Organisation zurück, und die schaltet dort prompt
     * wieder um. Wer auf der Fehlerliste wechselt, will die Fehler der neuen
     * Organisation sehen und nicht wieder bei null anfangen.
     *
     * Die Projektauswahl bleibt dabei zurück: Projekte gehören zu einer
     * Organisation, und die der alten hat in der neuen nichts mehr zu suchen.
     * Der Zeitraum und die übrigen Parameter der Seite bleiben stehen; man
     * wechselt die Organisation, um denselben Ausschnitt woanders zu sehen
     * ({@see FilterQuery}).
     */
    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('view', $organization);

        $request->user()->switchOrganization($organization);

        return redirect()
            ->to($this->samePageIn($organization, FilterQuery::withoutProjectSelection(url()->previous())))
            ->with('status', __('organizations.flash.switched', ['name' => $organization->name]));
    }

    /**
     * Dieselbe Seite in der angegebenen Organisation.
     *
     * Die vorige Adresse wird gegen die Routen gehalten, statt sie zu zerlegen:
     * nur die Route weiß, welcher Abschnitt die Organisation ist. Drei Fälle,
     * und der Reihe nach:
     *
     * 1. Die vorige Seite gehört zu keiner Organisation (Organisationsliste,
     *    Zugriffstoken) — dann bleibt es bei ihr. Sie zeigt nach dem Wechsel
     *    ohnehin die neue.
     * 2. Sie hängt allein an der Organisation (Fehlerliste, Versionen, …) — dann
     *    dieselbe Seite mit dem neuen Slug, samt Abfrage-Parametern: der Zeitraum
     *    der Filterleiste soll den Wechsel überleben. Projekte, die es in der
     *    neuen Organisation nicht gibt, übergeht der Filter von selbst.
     * 3. Sie hängt an einer Kennung (ein einzelner Fehler, eine Version) — die
     *    gibt es in der neuen Organisation nicht, und ein Link darauf endete in
     *    einer Zugriffs-Fehlermeldung. Dann in die Liste desselben Bereichs, und
     *    erst wenn es die nicht gibt, auf die Übersicht.
     */
    private function samePageIn(Organization $organization, string $previous): string
    {
        $route = $this->routeFor($previous);
        $name = $route?->getName();

        if ($route === null || $name === null || ! array_key_exists('organization', $route->parameters())) {
            return $previous;
        }

        if (Arr::except($route->parameters(), ['organization']) !== []) {
            $name = $this->listFor($name);
        }

        $target = route($name, ['organization' => $organization]);
        $query = parse_url($previous, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $target.'?'.$query : $target;
    }

    /**
     * Die Liste zu einer Detailseite: erst die des engsten Bereichs
     * (`performance.issues.show` → `performance.issues.index`), dann die des
     * Oberbereichs (`issues.tags.show` → `issues.index`), zuletzt die Übersicht.
     *
     * Genommen wird nur, was allein an der Organisation hängt — eine „Liste",
     * die selbst eine Kennung braucht, wäre dasselbe Problem eine Ebene höher.
     */
    private function listFor(string $name): string
    {
        $candidates = [
            Str::beforeLast($name, '.').'.index',
            Str::before($name, '.').'.index',
        ];

        foreach ($candidates as $candidate) {
            $route = Route::getRoutes()->getByName($candidate);

            if ($route !== null && $route->parameterNames() === ['organization']) {
                return $candidate;
            }
        }

        return 'dashboard';
    }

    /**
     * Die Route hinter einer Adresse — oder null, wenn keine passt (eine fremde
     * Adresse im Referrer, eine inzwischen abgeschaffte Seite).
     */
    private function routeFor(string $url): ?RoutingRoute
    {
        try {
            return Route::getRoutes()->match(Request::create($url));
        } catch (HttpException) {
            return null;
        }
    }
}
