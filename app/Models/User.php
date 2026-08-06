<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property int|null $current_organization_id
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /**
     * @use HasFactory<UserFactory>
     * @use HasApiTokens<ApiToken>
     */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    /**
     * Zuletzt gewählte Organisation. Kann null sein — ein frisch registriertes
     * Konto gehört noch keiner an.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * Die aktuelle Organisation, aber verlässlich: zeigt das Feld auf eine
     * Organisation, der dieses Konto nicht (mehr) angehört, wird stattdessen die
     * alphabetisch erste Mitgliedschaft genommen und das Feld nachgezogen.
     */
    public function resolveCurrentOrganization(): ?Organization
    {
        $current = $this->currentOrganization;

        if ($current !== null && $current->hasMember($this)) {
            return $current;
        }

        $fallback = $this->organizations()->orderBy('organizations.name')->first();

        $this->switchOrganization($fallback);

        return $fallback;
    }

    public function switchOrganization(?Organization $organization): void
    {
        if ($this->current_organization_id === $organization?->id) {
            return;
        }

        $this->current_organization_id = $organization?->id;
        $this->save();
        $this->unsetRelation('currentOrganization');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
