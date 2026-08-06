<?php

namespace App\Policies;

use App\Models\OrganizationInvitation;
use App\Models\User;

/**
 * Rechte an offenen Einladungen. Wer einladen darf, darf eine Einladung auch
 * wieder zurückziehen.
 */
class OrganizationInvitationPolicy
{
    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        return $user->can('invite', $invitation->organization);
    }
}
