<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Enums\QuotaScope;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

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
    /**
     * `HasApiTokens` macht die Organisation selbst zum Träger von Tokens — das
     * sind die organisationsweiten, die keinem Konto gehören.
     *
     * @use HasFactory<OrganizationFactory>
     * @use HasApiTokens<ApiToken>
     */
    use HasApiTokens, HasFactory;

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
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Die verbundenen Repositories (R2). An der Organisation und nicht am
     * Projekt: dasselbe Repository versorgt in aller Regel mehrere Projekte.
     *
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
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
     * Alle API-Tokens, die für diese Organisation gelten — persönliche wie
     * organisationsweite. Nicht zu verwechseln mit `tokens()` aus HasApiTokens:
     * das sind nur die organisationsweiten, deren Träger die Organisation selbst
     * ist.
     *
     * @return HasMany<ApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * Änderungsprotokoll dieser Organisation. Geschrieben wird ausschließlich
     * über App\Support\AuditLog.
     *
     * @return HasMany<AuditLogEntry, $this>
     */
    public function auditLogEntries(): HasMany
    {
        return $this->hasMany(AuditLogEntry::class);
    }

    /**
     * Organisationsweite Datenschutz-Regeln: die, die für alle Projekte gelten.
     *
     * Die Bedingung auf `project_id` gehört in die Beziehung und nicht an jeden
     * Aufruf: dieselbe Tabelle trägt auch die Regeln der einzelnen Projekte, und
     * ohne sie käme hier alles zusammen — samt der Möglichkeit, über diese
     * Beziehung versehentlich eine Projekt-Regel zu ändern.
     *
     * @return HasMany<ScrubRule, $this>
     */
    public function scrubRules(): HasMany
    {
        return $this->hasMany(ScrubRule::class)->whereNull('project_id');
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

    /**
     * Kontingente hängen über Ebene und Kennung an dieser Organisation und
     * nicht über einen Fremdschlüssel ({@see Quota}) — hinter einer Löschung
     * räumt deshalb dieser Haken auf.
     */
    protected static function booted(): void
    {
        self::deleted(static fn (self $organization) => Quota::forget(QuotaScope::Organization, $organization->id));
    }
}
