<?php

namespace App\Support;

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Organisations-Seiten. Was der Betrachter tun darf, entscheiden
 * auch hier die Policies — die Oberfläche blendet nur aus, was ohnehin
 * abgewiesen würde.
 */
final class OrganizationData
{
    /**
     * Detailseite einer Organisation: Stammdaten, Mitglieder, offene
     * Einladungen und Teams.
     *
     * @return array<string, mixed>
     */
    public static function detail(Organization $organization, User $viewer): array
    {
        $organization->load(['memberships.user', 'invitations', 'teams']);

        // Die Policies fragen je Mitgliedschaft nach deren Organisation. Ohne
        // diesen Rückverweis lädt jede einzelne sie erneut aus der Datenbank.
        $organization->memberships->each(
            fn (Membership $membership) => $membership->setRelation('organization', $organization),
        );

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'viewer' => [
                'id' => $viewer->id,
                'role' => $organization->roleFor($viewer)?->value,
            ],
            'permissions' => [
                'update' => Gate::forUser($viewer)->allows('update', $organization),
                'delete' => Gate::forUser($viewer)->allows('delete', $organization),
                'invite' => Gate::forUser($viewer)->allows('invite', $organization),
                'manageTeams' => Gate::forUser($viewer)->allows('manageTeams', $organization),
            ],
            'members' => $organization->memberships
                ->sortBy(fn (Membership $membership): string => (string) $membership->user->name)
                ->values()
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'userId' => $membership->user_id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->value,
                    'roleLabel' => $membership->role->label(),
                    'isSelf' => $membership->user_id === $viewer->id,
                    // Ob überhaupt eine andere Rolle vergeben werden darf; die
                    // konkrete Wahl prüft die Policy beim Absenden erneut.
                    'canUpdateRole' => Gate::forUser($viewer)->allows('updateRole', [$membership, OrganizationRole::Member]),
                    'canRemove' => Gate::forUser($viewer)->allows('delete', $membership),
                ])->all(),
            'invitations' => $organization->invitations
                ->sortBy(fn (OrganizationInvitation $invitation): string => (string) $invitation->email)
                ->values()
                ->map(fn (OrganizationInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'roleLabel' => $invitation->role->label(),
                    'expiresAt' => $invitation->expires_at->format('d.m.Y'),
                    'isExpired' => $invitation->isExpired(),
                ])->all(),
            'teams' => $organization->teams
                ->sortBy(fn (Team $team): string => (string) $team->name)
                ->values()
                ->map(fn (Team $team): array => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'href' => route('teams.show', $team),
                ])->all(),
            'roleOptions' => OrganizationRole::options(),
        ];
    }
}
