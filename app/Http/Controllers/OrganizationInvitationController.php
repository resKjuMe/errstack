<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\OrganizationRole;
use App\Http\Requests\StoreInvitationRequest;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Support\AuditLog;
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

        AuditLog::record(
            AuditAction::InvitationSent,
            $organization,
            subject: $invitation,
            subjectLabel: $invitation->email,
            changes: AuditLog::change('Rolle', null, $role->label()),
        );

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

        $before = $invitation->role;

        $invitation->update(['role' => $role]);

        if ($role !== $before) {
            AuditLog::record(
                AuditAction::InvitationRoleChanged,
                $invitation->organization,
                subject: $invitation,
                subjectLabel: $invitation->email,
                changes: AuditLog::change('Rolle', $before->label(), $role->label()),
            );
        }

        return back()->with('status', "Einladung an {$invitation->email}: Rolle auf {$role->label()} gesetzt.");
    }

    public function destroy(OrganizationInvitation $invitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        $email = $invitation->email;
        $role = $invitation->role;
        $organization = $invitation->organization;

        $invitation->delete();

        AuditLog::record(
            AuditAction::InvitationRevoked,
            $organization,
            subjectLabel: $email,
            changes: AuditLog::change('Rolle', $role->label(), null),
        );

        return back()->with('status', "Einladung an {$email} zurückgezogen.");
    }
}
