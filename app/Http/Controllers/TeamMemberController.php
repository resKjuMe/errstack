<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mitglieder einem Team zuordnen und wieder herausnehmen.
 */
class TeamMemberController extends Controller
{
    public function store(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize('manageMembers', $team);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()->findOrFail($validated['user_id']);

        // Wer nicht zur Organisation gehört, gehört auch in keins ihrer Teams.
        abort_unless($team->organization->hasMember($user), Response::HTTP_FORBIDDEN);

        $team->members()->syncWithoutDetaching([$user->id]);

        AuditLog::record(
            AuditAction::TeamMemberAdded,
            $team->organization,
            subject: $team,
            subjectLabel: $team->name,
            changes: AuditLog::change('member', null, $user->name),
        );

        return back()->with('status', __('teams.flash.member_added', [
            'name' => $user->name,
            'team' => $team->name,
        ]));
    }

    public function destroy(Team $team, User $user): RedirectResponse
    {
        Gate::authorize('manageMembers', $team);

        $team->members()->detach($user->id);

        AuditLog::record(
            AuditAction::TeamMemberRemoved,
            $team->organization,
            subject: $team,
            subjectLabel: $team->name,
            changes: AuditLog::change('member', $user->name, null),
        );

        return back()->with('status', __('teams.flash.member_removed', [
            'name' => $user->name,
            'team' => $team->name,
        ]));
    }
}
