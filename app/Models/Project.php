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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
 * @property bool $scrub_ip_addresses
 * @property bool $scrub_user_data
 * @property bool $scrub_attachments
 */
#[Fillable([
    'name',
    'platform',
    'default_environment',
    'resolution_behavior',
    'retention_days',
    'scrub_ip_addresses',
    'scrub_user_data',
    'scrub_attachments',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Name des Schlüssels, der beim Anlegen entsteht. Derselbe Name wie in der
     * Datenübernahme der bisherigen Projekt-Token.
     */
    public const FIRST_KEY_NAME = 'Standard';

    /**
     * In der Adresszeile steht der Slug hinter der Organisation
     * (`/organisationen/{organisation}/projekte/{projekt}`).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Legt ein Projekt samt Slug und erstem Client-Schlüssel an. Wie bei der
     * Organisation bewusst ein benannter Konstruktor statt eines
     * `creating`-Hooks: Seeder laufen mit abgeschalteten Model-Events und
     * würden ihn überspringen.
     *
     * Der erste Schlüssel entsteht sofort mit, damit ein frisches Projekt eine
     * DSN zum Kopieren hat und nicht erst eingerichtet werden muss.
     *
     * @param  array<string, mixed>  $attributes  weitere Einstellungen
     */
    public static function createFor(Organization $organization, string $name, Platform $platform, array $attributes = []): self
    {
        // Projekt und erster Schlüssel gehören zusammen: ein Projekt ohne
        // Schlüssel hätte keine Adresse, an die gemeldet werden könnte.
        return DB::transaction(function () use ($organization, $name, $platform, $attributes): self {
            $project = new self(array_merge($attributes, [
                'name' => $name,
                'platform' => $platform,
            ]));

            $project->organization_id = $organization->id;
            $project->slug = self::uniqueSlug($organization, $name);
            $project->save();

            ProjectKey::createFor($project, self::FIRST_KEY_NAME);

            return $project;
        });
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
     * Client-Schlüssel dieses Projekts — die DSNs, unter denen Meldungen
     * eingestellt werden.
     *
     * @return HasMany<ProjectKey, $this>
     */
    public function keys(): HasMany
    {
        return $this->hasMany(ProjectKey::class);
    }

    /**
     * Umgebungen dieses Projekts. Sie entstehen aus den eingehenden Meldungen
     * (Environment::record()) und werden hier nicht angelegt.
     *
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * Überwachte Cronjobs dieses Projekts. Sie entstehen in der Oberfläche oder
     * beim ersten Check-in, der seinen Zeitplan mitbringt (M1).
     *
     * @return HasMany<CronMonitor, $this>
     */
    public function cronMonitors(): HasMany
    {
        return $this->hasMany(CronMonitor::class);
    }

    /**
     * Gemessene Antwortzeiten dieses Projekts.
     *
     * Ausdrücklich getrennt von den Fehlermeldungen: eine Transaktion ist keine,
     * und keine Auswertung der einen soll die andere mitlesen.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Die Fehlergruppen dieses Projekts — je Fingerabdruck eine.
     *
     * @return HasMany<EventGroup, $this>
     */
    public function eventGroups(): HasMany
    {
        return $this->hasMany(EventGroup::class);
    }

    /**
     * Die projektweiten Regeln, mit denen das Grouping korrigiert wird (I5).
     *
     * @return HasMany<FingerprintRule, $this>
     */
    public function fingerprintRules(): HasMany
    {
        return $this->hasMany(FingerprintRule::class);
    }

    /**
     * Die projektweiten Regeln, nach denen von den Antwortzeiten eine Stichprobe
     * behalten wird (I9).
     *
     * Sie wirken ausschließlich auf Transaktionen. Fehler werden vollständig
     * behalten — auch dann, wenn eine Regel auf ihren Transaktionsnamen zutreffen
     * würde.
     *
     * @return HasMany<SamplingRule, $this>
     */
    public function samplingRules(): HasMany
    {
        return $this->hasMany(SamplingRule::class);
    }

    /**
     * Eigene Datenschutz-Regeln dieses Projekts.
     *
     * Nur die eigenen — die organisationsweiten hängen an der Organisation und
     * gelten hier mit. Wer beides braucht, nimmt
     * {@see ScrubRule::scopeEffectiveFor()}; eine Beziehung, die
     * fremde Datensätze mitliefert, wäre bei jedem Speichern eine Falle.
     *
     * @return HasMany<ScrubRule, $this>
     */
    public function scrubRules(): HasMany
    {
        return $this->hasMany(ScrubRule::class);
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
            'scrub_ip_addresses' => 'boolean',
            'scrub_user_data' => 'boolean',
            'scrub_attachments' => 'boolean',
        ];
    }
}
