<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Einladung an eine E-Mail-Adresse, die noch zu keinem Konto gehören muss. Der
 * Token steht im Link der Einladungs-Mail und ist die einzige Kennung, über die
 * die Einladung von außen erreichbar ist.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $invited_by_id
 * @property string $email
 * @property OrganizationRole $role
 * @property string $token
 * @property Carbon $expires_at
 */
#[Fillable(['email', 'role', 'invited_by_id'])]
class OrganizationInvitation extends Model
{
    /** Gültigkeitsdauer einer Einladung in Tagen. */
    public const LIFETIME_DAYS = 14;

    protected static function booted(): void
    {
        static::creating(function (self $invitation) {
            $invitation->token ??= Str::random(64);
            $invitation->expires_at ??= Carbon::now()->addDays(self::LIFETIME_DAYS);
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Passt die Einladung zu diesem Konto? Verglichen wird die Adresse, damit
     * ein weitergeleiteter Link nicht im falschen Konto landet.
     */
    public function isFor(User $user): bool
    {
        return Str::lower((string) $user->email) === Str::lower((string) $this->email);
    }

    public function url(): string
    {
        return route('invitations.show', ['token' => $this->token]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
        ];
    }
}
