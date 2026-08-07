<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\ResolutionBehavior;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Ein Projekt steht für genau eine überwachte Anwendung. Es gehört zu genau
 * einer Organisation; alle ab Phase P1 aufgenommenen Ereignisse hängen daran.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property Platform $platform
 * @property string $default_environment
 * @property ResolutionBehavior $resolution_behavior
 * @property int $retention_days
 * @property string $token
 */
#[Fillable(['name', 'platform', 'default_environment', 'resolution_behavior', 'retention_days'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * In der Adresszeile steht der Slug hinter der Organisation
     * (`/organisationen/{organisation}/projekte/{projekt}`).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Legt ein Projekt samt Slug und Token an. Wie bei der Organisation
     * bewusst ein benannter Konstruktor statt eines `creating`-Hooks: Seeder
     * laufen mit abgeschalteten Model-Events und würden ihn überspringen.
     *
     * @param  array<string, mixed>  $attributes  weitere Einstellungen
     */
    public static function createFor(Organization $organization, string $name, Platform $platform, array $attributes = []): self
    {
        $project = new self(array_merge($attributes, [
            'name' => $name,
            'platform' => $platform,
        ]));

        $project->organization_id = $organization->id;
        $project->slug = self::uniqueSlug($organization, $name);
        $project->token = self::freshToken();
        $project->save();

        return $project;
    }

    /**
     * Sprechende Kennung innerhalb der Organisation. Sie entsteht aus dem Namen
     * und wird bei Bedarf durchnummeriert; eine spätere Umbenennung lässt sie
     * absichtlich unangetastet, damit Links gültig bleiben.
     */
    public static function uniqueSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'projekt';
        $slug = $base;

        $taken = fn (string $candidate): bool => self::query()
            ->where('organization_id', $organization->id)
            ->where('slug', $candidate)
            ->exists();

        for ($suffix = 2; $taken($slug); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Neuer Sicherheits-Token. 32 Hex-Zeichen aus dem Zufallsgenerator des
     * Systems — derselbe Wert, den die SDKs später mitschicken.
     */
    public static function freshToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Zieht den Token neu. Ab dann werden Meldungen mit dem alten abgewiesen.
     */
    public function rotateToken(): void
    {
        $this->token = self::freshToken();
        $this->save();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Zuständige Teams — optional. Ohne Zuordnung ist das Projekt Sache der
     * ganzen Organisation.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'resolution_behavior' => ResolutionBehavior::class,
            'retention_days' => 'integer',
        ];
    }
}
