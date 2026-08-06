<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einladung aus der E-Mail annehmen. Der Token im Link ist die einzige
 * Kennung — deshalb hängt die Berechtigung hier nicht an einer Rolle, sondern
 * daran, dass die eingeladene Adresse zum angemeldeten Konto gehört.
 */
class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token): InertiaResponse
    {
        $invitation = $this->find($token);

        return Inertia::render('invitations/Accept', [
            'invitation' => [
                'organization' => $invitation->organization->name,
                'email' => $invitation->email,
                'roleLabel' => $invitation->role->label(),
                'expiresAt' => $invitation->expires_at->format('d.m.Y'),
                'isExpired' => $invitation->isExpired(),
                'isForCurrentUser' => $invitation->isFor($request->user()),
            ],
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->find($token);
        $user = $request->user();

        // Ein weitergeleiteter Link darf nicht im falschen Konto landen.
        abort_unless($invitation->isFor($user), Response::HTTP_FORBIDDEN);

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => 'Diese Einladung ist abgelaufen. Bitte um eine neue bitten.',
            ]);
        }

        $organization = $invitation->organization;

        // Beitritt und Verbrauch der Einladung gehören zusammen — sonst bliebe
        // eine bereits genutzte Einladung offen stehen.
        DB::transaction(function () use ($invitation, $organization, $user): void {
            $organization->setRole($user, $invitation->role);
            $invitation->delete();
        });

        $user->switchOrganization($organization);

        return redirect()
            ->route('organizations.show', $organization)
            ->with('status', "Willkommen bei {$organization->name}.");
    }

    private function find(string $token): OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->with('organization')
            ->where('token', $token)
            ->firstOrFail();
    }
}
