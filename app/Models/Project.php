<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\QuotaScope;
use App\Enums\ResolutionBehavior;
use App\Support\Attachments\AttachmentStore;
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
 * @property int $attachment_retention_days
 * @property int|null $replay_retention_days
 * @property int $digest_window_minutes
 * @property int $digest_min_events
 * @property int $digest_max_events
 * @property bool $spike_protection_enabled
 * @property float $spike_threshold_factor
 * @property int $spike_minimum_events
 * @property int $spike_release_minutes
 * @property bool $auto_assign_suspect_commits
 * @property bool $scrub_ip_addresses
 * @property bool $scrub_user_data
 * @property bool $scrub_attachments
 * @property bool $filter_browser_extensions
 * @property bool $filter_legacy_browsers
 * @property bool $filter_localhost
 * @property bool $filter_crawlers
 * @property bool $filter_message_patterns
 * @property bool $filter_ip_addresses
 * @property bool $filter_releases
 * @property bool $ownership_auto_assign
 */
#[Fillable([
    'name',
    'platform',
    'default_environment',
    'resolution_behavior',
    'retention_days',
    'attachment_retention_days',
    'replay_retention_days',
    'digest_window_minutes',
    'digest_min_events',
    'digest_max_events',
    'spike_protection_enabled',
    'spike_threshold_factor',
    'spike_minimum_events',
    'spike_release_minutes',
    'auto_assign_suspect_commits',
    'scrub_ip_addresses',
    'scrub_user_data',
    'scrub_attachments',
    'filter_browser_extensions',
    'filter_legacy_browsers',
    'filter_localhost',
    'filter_crawlers',
    'filter_message_patterns',
    'filter_ip_addresses',
    'filter_releases',
    'ownership_auto_assign',
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
     * Was ein gelöschtes Projekt außerhalb der Datenbank hinterlässt.
     *
     * Die Zeilen nehmen die Fremdschlüssel mit — die Dateien auf dem Laufwerk
     * nicht. Bei den Anhängen (M5) ist das keine Kleinigkeit: sie sind der
     * größte Posten des Bestands, und mit der Zeile fällt der einzige Verweis
     * weg, über den das nächtliche Aufräumen sie noch finden könnte. Ein
     * gelöschtes Projekt hinterließe damit dauerhaft belegten Platz, den niemand
     * mehr erklären kann.
     *
     * `deleted` und nicht `deleting`: erst wenn das Löschen durch ist, sollen die
     * Dateien fallen. Der Fehlerfall bleibt im Protokoll und hält das Löschen
     * nicht auf ({@see AttachmentStore::forgetProject()}) — ein Projekt, das laut
     * Datenbank weg ist, aber laut Oberfläche nicht gelöscht werden konnte, wäre
     * die schlechtere Antwort.
     *
     * Aus demselben Grund hängt hier das Vergessen der Kontingente: sie hängen
     * über Ebene und Kennung an diesem Datensatz und nicht über einen
     * Fremdschlüssel ({@see Quota}). Ohne den Haken läge eine Grenze für eine
     * Kennung herum, die es nicht mehr gibt.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $project): void {
            app(AttachmentStore::class)->forgetProject($project->id);

            Quota::forget(QuotaScope::Project, $project->id);
        });
    }

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
            $project = new self(array_merge([
                // Die Frist der Anhänge (M5) kommt aus der Einstellung des
                // Betreibers und nicht aus dem Spalten-Vorgabewert: der steht im
                // Schema und ließe sich nach dem Migrieren nicht mehr ändern. Sie
                // steht **vor** `$attributes`, damit ein Aufrufer sie überschreiben
                // kann.
                'attachment_retention_days' => (int) config('attachments.retention_days'),
            ], $attributes, [
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
     * Überwachte Ziele dieses Projekts — die Erreichbarkeits-Prüfungen von
     * außen (M2).
     *
     * @return HasMany<UptimeMonitor, $this>
     */
    public function uptimeMonitors(): HasMany
    {
        return $this->hasMany(UptimeMonitor::class);
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
     * Die Schwellwert-Alarme dieses Projekts (A3).
     *
     * `cascadeOnDelete` an der Fremdschlüssel-Beziehung: ein gelöschtes Projekt
     * nimmt seine Alarme mit. Sie beziehen sich auf Kennzahlen, die es dann
     * nicht mehr gibt — eine verwaiste Regel würde jede Minute eine leere
     * Auswertung rechnen.
     *
     * @return HasMany<MetricAlert, $this>
     */
    public function metricAlerts(): HasMany
    {
        return $this->hasMany(MetricAlert::class);
    }

    /**
     * Die Alarm-Regeln für Fehler (A2).
     *
     * `cascadeOnDelete` wie bei den Schwellwert-Alarmen: ein gelöschtes Projekt
     * nimmt seine Regeln mit. Sie beziehen sich auf Fehler, die es dann nicht
     * mehr gibt.
     *
     * @return HasMany<IssueAlertRule, $this>
     */
    public function issueAlertRules(): HasMany
    {
        return $this->hasMany(IssueAlertRule::class);
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
     * Die Auslösungen des Ausschlag-Schutzes (A7) — jede eine Drosselung mit
     * Anfang, Ende und der Menge, die sie verworfen hat.
     *
     * @return HasMany<SpikeProtectionState, $this>
     */
    public function spikeProtectionStates(): HasMany
    {
        return $this->hasMany(SpikeProtectionState::class);
    }

    /**
     * Die Aufnahmemenge je Minute (A7) — der Verlauf, an dem eine Spitze
     * überhaupt erst als Spitze erkennbar ist.
     *
     * @return HasMany<IngestVolume, $this>
     */
    public function ingestVolumes(): HasMany
    {
        return $this->hasMany(IngestVolume::class);
    }

    /**
     * Die abweichenden Schwellen der Leistungserkennung.
     *
     * Ausdrücklich nur die **Abweichungen**: ein Projekt ohne eine einzige
     * Zeile hier ist eines mit den Vorgabewerten, nicht eines ohne Erkennung
     * ({@see PerformanceSetting}).
     *
     * @return HasMany<PerformanceSetting, $this>
     */
    public function performanceSettings(): HasMany
    {
        return $this->hasMany(PerformanceSetting::class);
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
     * Die Listen der Eingangsfilter (I8): gesperrte Fehlertexte, Absender,
     * Releases und die Untergrenzen für Browser-Fassungen.
     *
     * @return HasMany<InboundFilterRule, $this>
     */
    public function inboundFilterRules(): HasMany
    {
        return $this->hasMany(InboundFilterRule::class);
    }

    /**
     * Die Zuständigkeits-Regeln (R6): wer sich um einen Fehler kümmert,
     * abgeleitet aus dem Ort, an dem er passiert ist.
     *
     * @return HasMany<OwnershipRule, $this>
     */
    public function ownershipRules(): HasMany
    {
        return $this->hasMany(OwnershipRule::class);
    }

    /**
     * Die hochgeladenen Bauartefakte aller Versionen dieses Projekts (R5).
     *
     * Sie hängen an einer Version und stehen trotzdem hier: die Beziehung ist
     * das, was die Schnittstelle beim Löschen prüfen lässt, ob eine Kennung aus
     * der Adresszeile überhaupt zu diesem Projekt gehört.
     *
     * @return HasMany<ReleaseArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ReleaseArtifact::class);
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
            'attachment_retention_days' => 'integer',
            'replay_retention_days' => 'integer',
            'digest_window_minutes' => 'integer',
            'digest_min_events' => 'integer',
            'digest_max_events' => 'integer',
            'spike_protection_enabled' => 'boolean',
            'spike_threshold_factor' => 'float',
            'spike_minimum_events' => 'integer',
            'spike_release_minutes' => 'integer',
            'auto_assign_suspect_commits' => 'boolean',
            'scrub_ip_addresses' => 'boolean',
            'scrub_user_data' => 'boolean',
            'scrub_attachments' => 'boolean',
            'filter_browser_extensions' => 'boolean',
            'filter_legacy_browsers' => 'boolean',
            'filter_localhost' => 'boolean',
            'filter_crawlers' => 'boolean',
            'filter_message_patterns' => 'boolean',
            'filter_ip_addresses' => 'boolean',
            'filter_releases' => 'boolean',
            'ownership_auto_assign' => 'boolean',
        ];
    }
}
