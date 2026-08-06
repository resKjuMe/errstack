<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Rolle eines Mitglieds ändern und Mitglieder entfernen. Was dabei erlaubt ist
 * — eigene Rolle, letzter Besitzer, Organisation verlassen — steht vollständig
 * in der MembershipPolicy.
 */
class MembershipController extends Controller
{
    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $role = OrganizationRole::from((string) $validated['role']);

        Gate::authorize('updateRole', [$membership, $role]);

        $membership->update(['role' => $role]);

        return back()->with('status', "Rolle von {$membership->user->name} auf {$role->label()} gesetzt.");
    }

    public function destroy(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        $user = $membership->user;
        $organization = $membership->organization;
        $isSelf = $membership->user_id === $request->user()->id;

        $membership->delete();

        // Wer die Organisation verlassen hat, darf sie auch nicht mehr als
        // aktive Organisation behalten — dasselbe gilt für die entfernte Person
        // beim nächsten Aufruf.
        $user->unsetRelation('currentOrganization');
        $user->resolveCurrentOrganization();

        // Teams gehören zur Organisation: mit der Mitgliedschaft endet auch die
        // Zuordnung zu deren Teams.
        $user->teams()->detach($organization->teams()->pluck('teams.id')->all());

        if ($isSelf) {
            return redirect()
                ->route('organizations.index')
                ->with('status', "Organisation „{$organization->name}“ verlassen.");
        }

        return back()->with('status', "{$user->name} wurde entfernt.");
    }
}
