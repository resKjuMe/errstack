<?php

namespace App\Http\Controllers;

use App\Http\Requests\TeamRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Teams einer Organisation anlegen, ansehen, umbenennen und löschen.
 */
class TeamController extends Controller
{
    public function store(TeamRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageTeams', $organization);

        $team = $organization->teams()->create($request->validated());

        return redirect()
            ->route('teams.show', $team)
            ->with('status', "Team „{$team->name}“ angelegt.");
    }

    public function show(Request $request, Team $team): InertiaResponse
    {
        Gate::authorize('view', $team);

        $viewer = $request->user();
        $team->load(['organization', 'members']);

        $memberIds = $team->members->pluck('id')->all();

        return Inertia::render('teams/Show', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'organization' => [
                'slug' => $team->organization->slug,
                'name' => $team->organization->name,
                'href' => route('organizations.show', $team->organization),
            ],
            'permissions' => [
                'manage' => Gate::forUser($viewer)->allows('manageMembers', $team),
            ],
            'members' => $team->members
                ->sortBy(fn (User $member): string => (string) $member->name)
                ->values()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                ])->all(),
            // Zur Auswahl stehen nur Mitglieder der Organisation — ein Team
            // reicht nie über sie hinaus.
            'candidates' => $team->organization->memberships()
                ->with('user')
                ->get()
                ->reject(fn (Membership $membership): bool => in_array($membership->user_id, $memberIds, true))
                ->sortBy(fn (Membership $membership): string => (string) $membership->user->name)
                ->values()
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->user_id,
                    'name' => $membership->user->name,
                ])->all(),
        ]);
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team->update($request->validated());

        return back()->with('status', 'Team gespeichert.');
    }

    public function destroy(Team $team): RedirectResponse
    {
        Gate::authorize('delete', $team);

        $organization = $team->organization;
        $name = $team->name;
        $team->delete();

        return redirect()
            ->route('organizations.show', $organization)
            ->with('status', "Team „{$name}“ gelöscht.");
    }
}
