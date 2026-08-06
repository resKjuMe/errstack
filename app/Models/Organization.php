<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Oberste Klammer der Mandantenfähigkeit: alle fachlichen Daten hängen an
 * genau einer Organisation, jeder Zugriff läuft über die OrganizationPolicy.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
#[Fillable(['name'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * In der Adresszeile steht der Slug, nicht die laufende Nummer.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Legt eine Organisation samt Slug an. Bewusst als benannter Konstruktor
     * statt als `creating`-Hook: Seeder laufen mit abgeschalteten Model-Events,
     * ein Hook würde dort stillschweigend übersprungen.
     */
    public static function createNamed(string $name): self
    {
        $organization = new self(['name' => $name]);
        $organization->slug = self::uniqueSlug($name);
        $organization->save();

        return $organization;
    }

    /**
     * Der Slug ist die sprechende, stabile Kennung nach außen. Er entsteht aus
     * dem Namen und wird bei Bedarf durchnummeriert; eine spätere Umbenennung
     * lässt ihn absichtlich unangetastet, damit Links gültig bleiben.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organisation';
        $slug = $base;

        for ($suffix = 2; self::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Mitgliedschaften samt Rolle. Bewusst als eigene Beziehung statt als
     * Pivot-Daten einer belongsToMany: die Rolle ist ein getyptes Feld, das an
     * vielen Stellen gelesen wird.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * Eingerichtete Benachrichtigungswege. Sie hängen an der Organisation und
     * nicht am einzelnen Projekt: dieselbe Bereitschaft will nicht je Projekt
     * neu eingetragen werden.
     *
     * @return HasMany<NotificationChannel, $this>
     */
    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    /**
     * Mitgliedschaft dieses Nutzers — oder null, wenn er der Organisation nicht
     * angehört. Ist die Beziehung bereits geladen (Listen, Detailseite), wird
     * sie genutzt, statt erneut zu fragen.
     */
    public function membershipFor(?User $user): ?Membership
    {
        if ($user === null) {
            return null;
        }

        if ($this->relationLoaded('memberships')) {
            return $this->memberships->firstWhere('user_id', $user->id);
        }

        return $this->memberships()->firstWhere('user_id', $user->id);
    }

    public function roleFor(?User $user): ?OrganizationRole
    {
        return $this->membershipFor($user)?->role;
    }

    public function hasMember(?User $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    /**
     * Fügt einen Nutzer hinzu oder ändert seine Rolle, ohne die Mitgliedschaft
     * zu verdoppeln.
     */
    public function setRole(User $user, OrganizationRole $role): Membership
    {
        $membership = $this->memberships()->updateOrCreate(
            ['user_id' => $user->id],
            ['role' => $role],
        );

        $this->unsetRelation('memberships');

        return $membership;
    }
}
