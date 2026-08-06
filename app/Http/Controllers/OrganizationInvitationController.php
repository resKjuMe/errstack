<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Requests\StoreInvitationRequest;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

/**
 * Einladungen in eine Organisation aussprechen und zurückziehen.
 */
class OrganizationInvitationController extends Controller
{
    public function store(StoreInvitationRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('invite', $organization);

        $invitation = $organization->invitations()->create([
            'email' => (string) $request->input('email'),
            'role' => OrganizationRole::from((string) $request->input('role')),
            'invited_by_id' => $request->user()->id,
        ]);

        // Die Adresse gehört noch zu keinem Konto — die Mail geht deshalb an die
        // Adresse selbst, nicht an einen Nutzer.
        Mail::to($invitation->email)->send(new OrganizationInvitationMail($invitation));

        return back()->with('status', "Einladung an {$invitation->email} verschickt.");
    }

    public function destroy(OrganizationInvitation $invitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        $email = $invitation->email;
        $invitation->delete();

        return back()->with('status', "Einladung an {$email} zurückgezogen.");
    }
}
