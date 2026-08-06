<?php

namespace App\Support;

use App\Enums\ApiScope;
use App\Enums\ApiTokenKind;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Token-Seite. Wie bei OrganizationData entscheiden die Policies,
 * was die Oberfläche überhaupt anbietet — angezeigt wird nur, was hinterher auch
 * durchgeht.
 *
 * Der Token-Wert kommt hier nie vor: gespeichert ist nur sein Abdruck. Den
 * Klartext gibt es einmalig direkt nach dem Anlegen, und den übergibt der
 * Controller getrennt.
 */
final class ApiTokenData
{
    /**
     * @return array<string, mixed>
     */
    public static function index(Organization $organization, User $viewer): array
    {
        $tokens = $organization->apiTokens()
            ->with(['createdBy', 'tokenable'])
            ->orderBy('name')
            ->get();

        // Die Policy fragt je Token nach dessen Organisation. Ohne diesen
        // Rückverweis lädt jedes einzelne sie erneut aus der Datenbank.
        $tokens->each(fn (ApiToken $token) => $token->setRelation('organization', $organization));

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'permissions' => [
                'create' => Gate::forUser($viewer)->allows('create', [ApiToken::class, $organization]),
                'createShared' => Gate::forUser($viewer)->allows('createShared', [ApiToken::class, $organization]),
            ],
            'tokens' => $tokens->map(fn (ApiToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'kind' => $token->kind()->value,
                'kindLabel' => $token->kind()->label(),
                'owner' => self::owner($token),
                'scopes' => array_map(fn (ApiScope $scope): array => [
                    'value' => $scope->value,
                    'label' => $scope->label(),
                ], $token->scopes()),
                'createdAt' => $token->created_at?->toIso8601String(),
                'createdBy' => $token->createdBy?->name,
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'expiresAt' => $token->expires_at?->toIso8601String(),
                'isExpired' => $token->isExpired(),
                'canRevoke' => Gate::forUser($viewer)->allows('delete', $token),
            ])->all(),
            'kinds' => array_map(fn (ApiTokenKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'allowed' => $kind === ApiTokenKind::Personal
                    || Gate::forUser($viewer)->allows('createShared', [ApiToken::class, $organization]),
            ], ApiTokenKind::cases()),
            // Nach Überschriften gebündelt, und je Bereich vermerkt, ob die
            // eigene Rolle ihn hergibt: gesperrte Kästchen erklären mehr als
            // eine Fehlermeldung nach dem Absenden.
            'scopeGroups' => self::scopeGroups($organization, $viewer),
        ];
    }

    /**
     * @return list<array{label: string, scopes: list<array{value: string, label: string, allowed: bool}>}>
     */
    private static function scopeGroups(Organization $organization, User $viewer): array
    {
        $groups = [];

        foreach (ApiScope::all() as $scope) {
            $groups[$scope->group()][] = [
                'value' => $scope->value,
                'label' => $scope->label(),
                'allowed' => Gate::forUser($viewer)->allows('grantScope', [ApiToken::class, $organization, $scope]),
            ];
        }

        $result = [];

        foreach ($groups as $label => $scopes) {
            $result[] = ['label' => (string) $label, 'scopes' => $scopes];
        }

        return $result;
    }

    /**
     * Wem das Token gehört: bei einem persönlichen das Konto, bei einem
     * organisationsweiten die Organisation.
     */
    private static function owner(ApiToken $token): string
    {
        $tokenable = $token->tokenable;

        if ($tokenable instanceof User) {
            return $tokenable->name;
        }

        return $tokenable instanceof Organization ? $tokenable->name : '—';
    }
}
