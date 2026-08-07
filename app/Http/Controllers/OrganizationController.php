<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\OrganizationRole;
use App\Http\Requests\OrganizationRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\AuditLog;
use App\Support\OrganizationData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

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
     */
    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('view', $organization);

        $request->user()->switchOrganization($organization);

        return back()->with('status', __('organizations.flash.switched', ['name' => $organization->name]));
    }
}
