<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use App\Models\User;

/**
 * Rechte an offenen Einladungen. Wer einladen darf, darf eine Einladung auch
 * nachträglich ändern oder zurückziehen — für die Besitzer-Rolle gilt dabei
 * dieselbe Schranke wie beim Einladen selbst.
 */
class OrganizationInvitationPolicy
{
    public function update(User $user, OrganizationInvitation $invitation, OrganizationRole $role): bool
    {
        return $user->can('invite', [$invitation->organization, $role]);
    }

    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        return $user->can('invite', $invitation->organization);
    }
}
