<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\OrganizationRole;
use App\Http\Requests\StoreInvitationRequest;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Locales;
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
            changes: AuditLog::roleChange(null, $role),
        );

        // Gibt es zu der Adresse schon ein Konto, geht die Mail an dieses — nur
        // so kennt Laravel die Sprache des Empfängers (HasLocalePreference).
        // Sonst geht sie an die bloße Adresse, und mangels Anhaltspunkt in der
        // Vorgabe der Anwendung: die Sprache des Einladenden wäre geraten.
        $account = User::where('email', $invitation->email)->first();

        Mail::to($account ?? $invitation->email)
            ->locale($account?->preferredLocale() ?? Locales::fallback())
            ->send(new OrganizationInvitationMail($invitation));

        return back()->with('status', __('organizations.flash.invitation_sent', ['email' => $invitation->email]));
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
                changes: AuditLog::roleChange($before, $role),
            );
        }

        return back()->with('status', __('organizations.flash.invitation_role_changed', [
            'email' => $invitation->email,
            'role' => $role->label(),
        ]));
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
            changes: AuditLog::roleChange($role, null),
        );

        return back()->with('status', __('organizations.flash.invitation_withdrawn', ['email' => $email]));
    }
}
