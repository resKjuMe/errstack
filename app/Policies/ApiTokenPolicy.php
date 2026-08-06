<?php

namespace App\Policies;

use App\Enums\ApiScope;
use App\Enums\ApiTokenKind;
use App\Enums\OrganizationRole;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;

/**
 * Rechte an API-Tokens.
 *
 * Leitgedanke: ein Token darf nie mehr können als die Person, die es ausstellt.
 * Deshalb hängt nicht nur das Anlegen an der Rolle, sondern jeder einzelne
 * Geltungsbereich (grantScope) — sonst könnte ein Lesender sich ein Token mit
 * Schreibrechten ausstellen und damit die Rangfolge der Rollen aushebeln.
 */
class ApiTokenPolicy
{
    /**
     * Die Tokens einer Organisation sehen (Namen, Bereiche, letzte Nutzung — nie
     * den Wert selbst, der ist nirgends gespeichert).
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    /**
     * Ein eigenes, persönliches Token anlegen darf jedes Mitglied. Womit es
     * ausgestattet werden darf, entscheidet grantScope().
     */
    public function create(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    /**
     * Ein organisationsweites Token anlegen — ab der Verwaltung. Es überdauert
     * jede Mitgliedschaft und ist damit das mächtigere der beiden.
     */
    public function createShared(User $user, Organization $organization): bool
    {
        return $organization->roleFor($user)?->atLeast(OrganizationRole::Admin) === true;
    }

    /**
     * Diesen Geltungsbereich vergeben.
     */
    public function grantScope(User $user, Organization $organization, ApiScope $scope): bool
    {
        return $organization->roleFor($user)?->atLeast($scope->minimumRole()) === true;
    }

    /**
     * Widerrufen. Das eigene persönliche Token darf jeder zurückziehen; fremde
     * und organisationsweite nur die Verwaltung — die braucht diesen Griff, wenn
     * ein Token abgeflossen ist.
     */
    public function delete(User $user, ApiToken $token): bool
    {
        if ($token->kind() === ApiTokenKind::Personal && $token->tokenable_id === $user->id) {
            return true;
        }

        return $this->createShared($user, $token->organization);
    }
}
