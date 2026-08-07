<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Requests\StoreInvitationRequest;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Einladungen in eine Organisation aussprechen, ändern und zurückziehen.
 */
class OrganizationInvitationController extends Controller
{
    public function store(StoreInvitationRequest $request, Organization $organization): RedirectResponse
    {
        $role = OrganizationRole::from((string) $request->validated('role'));

        Gate::authorize('invite', [$organization, $role]);

        $invitation = $organization->invitations()->create([
            'email' => (string) $request->validated('email'),
            'role' => $role,
            'invited_by_id' => $request->user()->id,
        ]);

        // Die Adresse gehört noch zu keinem Konto — die Mail geht deshalb an die
        // Adresse selbst, nicht an einen Nutzer.
        Mail::to($invitation->email)->send(new OrganizationInvitationMail($invitation));

        return back()->with('status', "Einladung an {$invitation->email} verschickt.");
    }

    /**
     * Rolle einer noch offenen Einladung ändern, ohne sie neu verschicken zu
     * müssen — der Link aus der Mail bleibt gültig.
     */
    public function update(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $role = OrganizationRole::from((string) $validated['role']);

        Gate::authorize('update', [$invitation, $role]);

        $invitation->update(['role' => $role]);

        return back()->with('status', "Einladung an {$invitation->email}: Rolle auf {$role->label()} gesetzt.");
    }

    public function destroy(OrganizationInvitation $invitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        $email = $invitation->email;
        $invitation->delete();

        return back()->with('status', "Einladung an {$email} zurückgezogen.");
    }
}
