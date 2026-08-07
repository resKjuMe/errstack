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
 * auch hier die Policies — die Oberfläche zeigt nur an, was ohnehin durchginge.
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
        $organization->invitations->each(
            fn (OrganizationInvitation $invitation) => $invitation->setRelation('organization', $organization),
        );

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
                'notificationsHref' => route('notifications.index', $organization),
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
                'viewAuditLog' => Gate::forUser($viewer)->allows('viewAuditLog', $organization),
            ],
            'auditLogHref' => route('organizations.audit-log.index', $organization),
            // Die Datenschutz-Regeln darf jedes Mitglied ansehen — anders als das
            // Änderungsprotokoll enthalten sie keine Angaben über Personen,
            // sondern nur, welche Angaben nicht gespeichert werden.
            'privacyHref' => route('organizations.privacy.index', $organization),
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
                    // Nur die Rollen, die dieser Betrachter dieser Person auch
                    // wirklich geben darf — sonst böte das Auswahlfeld Rollen
                    // an, die der Server hinterher abweist.
                    'assignableRoles' => self::rolesFor(
                        fn (OrganizationRole $role): bool => Gate::forUser($viewer)->allows('updateRole', [$membership, $role]),
                        $membership->role,
                    ),
                    'roleHint' => self::roleHint($membership, $viewer),
                    'canRemove' => Gate::forUser($viewer)->allows('delete', $membership),
                ])->all(),
            'invitations' => $organization->invitations
                ->sortBy(fn (OrganizationInvitation $invitation): string => (string) $invitation->email)
                ->values()
                ->map(fn (OrganizationInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'roleLabel' => $invitation->role->label(),
                    'expiresAt' => Formats::date($invitation->expires_at),
                    'isExpired' => $invitation->isExpired(),
                    'assignableRoles' => self::rolesFor(
                        fn (OrganizationRole $role): bool => Gate::forUser($viewer)->allows('update', [$invitation, $role]),
                        $invitation->role,
                    ),
                ])->all(),
            'teams' => $organization->teams
                ->sortBy(fn (Team $team): string => (string) $team->name)
                ->values()
                ->map(fn (Team $team): array => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'href' => route('teams.show', $team),
                ])->all(),
            'invitableRoles' => self::rolesFor(
                fn (OrganizationRole $role): bool => Gate::forUser($viewer)->allows('invite', [$organization, $role]),
            ),
        ];
    }

    /**
     * Rollen, die der Prüfung standhalten — die aktuelle Rolle bleibt immer
     * dabei, damit das Auswahlfeld den Ist-Zustand anzeigen kann.
     *
     * @param  callable(OrganizationRole): bool  $allowed
     * @return list<array{value: string, label: string}>
     */
    private static function rolesFor(callable $allowed, ?OrganizationRole $current = null): array
    {
        $roles = array_values(array_filter(
            OrganizationRole::cases(),
            fn (OrganizationRole $role): bool => $role === $current || $allowed($role),
        ));

        // Bleibt nur die aktuelle Rolle übrig, gibt es nichts zu wählen.
        if ($roles === [$current]) {
            return [];
        }

        return array_map(
            fn (OrganizationRole $role): array => ['value' => $role->value, 'label' => $role->label()],
            $roles,
        );
    }

    /**
     * Warum die Rolle unverändert bleibt. Ohne den Hinweis sähe es aus, als sei
     * das Ändern schlicht nicht vorgesehen.
     */
    private static function roleHint(Membership $membership, User $viewer): ?string
    {
        if ($membership->user_id === $viewer->id) {
            return __('organizations.members.hint_self');
        }

        if ($membership->role === OrganizationRole::Owner
            && $membership->organization->roleFor($viewer) !== OrganizationRole::Owner) {
            return __('organizations.members.hint_owner');
        }

        // Der letzte Besitzer braucht keinen eigenen Hinweis: er ist zwangsläufig
        // der Betrachter selbst und liest damit schon den Hinweis von oben.
        return null;
    }
}
