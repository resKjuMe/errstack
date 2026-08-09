<?php

namespace App\Models;

use App\Console\Commands\SweepUptimeMonitorsCommand;
use App\Enums\HttpMethod;
use App\Enums\UptimeCheckOutcome;
use App\Enums\UptimeStatus;
use App\Support\Uptime\StatusExpectation;
use App\Support\Uptime\UptimeRecorder;
use Database\Factories\UptimeMonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ein überwachtes Ziel: eine Adresse, die in festem Takt von außen aufgerufen
 * wird.
 *
 * Das ist der Fall, den keine Meldung aus der Anwendung abdecken kann. Ein
 * Fehler meldet sich, weil die Anwendung läuft; ein Totalausfall erzeugt
 * gerade **keine** Fehler — es läuft nichts, was melden könnte. Deshalb steht
 * die Prüfung außerhalb: sie fragt nach, statt zu warten.
 *
 * Der Monitor kennt seinen Takt, seine Erwartung und seinen Zustand; die
 * einzelnen Messungen stehen im Verlauf ({@see UptimeCheck}), die daraus
 * abgeleiteten Vorfälle als Ausfall ({@see UptimeOutage}).
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 * @property string $url
 * @property HttpMethod $method
 * @property list<array{name: string, value: string}>|null $headers
 * @property string|null $body
 * @property string $expected_status_codes
 * @property string|null $expected_content
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property int $confirmation_retries
 * @property int $confirmation_delay_seconds
 * @property int $failure_threshold
 * @property int $recovery_threshold
 * @property bool $follow_redirects
 * @property bool $verify_tls
 * @property bool $is_active
 * @property UptimeStatus $status
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $next_check_at
 * @property-read Project $project
 */
#[Fillable([
    'name',
    'url',
    'method',
    'headers',
    'body',
    'expected_status_codes',
    'expected_content',
    'interval_seconds',
    'timeout_seconds',
    'confirmation_retries',
    'confirmation_delay_seconds',
    'failure_threshold',
    'recovery_threshold',
    'follow_redirects',
    'verify_tls',
    'is_active',
])]
class UptimeMonitor extends Model
{
    /** @use HasFactory<UptimeMonitorFactory> */
    use HasFactory;

    /**
     * Der kürzeste zulässige Takt, in Sekunden.
     *
     * Eine Minute, und das ist keine willkürliche Grenze: der Zeitplan der
     * Anwendung löst minütlich aus ({@see SweepUptimeMonitorsCommand}).
     * Ein feinerer Takt wäre eine Angabe, die niemand einhalten kann.
     */
    public const MINIMUM_INTERVAL_SECONDS = 60;

    /**
     * Wie viele Messungen der Antwortzeit-Verlauf zeigt.
     *
     * Bei einem Takt von einer Minute sind das zwei Stunden — genug, um einen
     * Einbruch als Verlauf zu sehen, und wenig genug, dass die Kurve nicht zu
     * einem Balken zusammenläuft.
     */
    public const HISTORY_LIMIT = 120;

    /**
     * Wie viele Ausfälle die Übersicht zeigt.
     */
    public const OUTAGE_LIMIT = 20;

    /**
     * In der Adresszeile steht die Kennung, nicht die Nummer — dieselbe
     * Begründung wie beim Cronjob-Monitor: ein Link aus einer Meldung soll auf
     * etwas zeigen, das man wiedererkennt.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Legt einen Monitor an und setzt gleich seine erste Fälligkeit.
     *
     * Benannter Konstruktor wie beim Cronjob-Monitor, und aus demselben Grund:
     * `next_check_at` ergibt sich aus dem Takt und darf nicht vom Aufrufer
     * kommen — ein Monitor ohne Fälligkeit wäre für den Sweep unsichtbar und
     * würde nie prüfen.
     *
     * Die erste Prüfung ist **sofort** fällig und nicht erst nach einem Takt:
     * wer eine Überwachung anlegt, will wissen, ob sie greift, und nicht fünf
     * Minuten auf die erste Antwort warten.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createFor(Project $project, string $name, array $attributes = [], ?string $slug = null): self
    {
        $monitor = new self($attributes + ['name' => $name]);

        $monitor->project_id = $project->id;
        $monitor->slug = self::uniqueSlug($project, $slug ?? $name);
        $monitor->status = UptimeStatus::Unknown;
        $monitor->next_check_at = Carbon::now();
        $monitor->save();

        return $monitor;
    }

    /**
     * Sprechende Kennung innerhalb des Projekts.
     *
     * Anders als beim Cronjob steht sie in keinem fremden Code — sie taucht nur
     * in Adressen und Meldungen auf. Trotzdem bleibt sie beim Umbenennen
     * unangetastet: Verweise auf einen Vorfall sollen später noch dorthin
     * führen, wo er stand.
     */
    public static function uniqueSlug(Project $project, string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'monitor';
        $slug = $base;

        $taken = fn (string $candidate): bool => self::query()
            ->where('project_id', $project->id)
            ->where('slug', $candidate)
            ->exists();

        for ($suffix = 2; $taken($slug); $suffix++) {
            $slug = Str::limit($base, 58, '')."-{$suffix}";
        }

        return $slug;
    }

    /**
     * Die Erwartung an den Statuscode als Wert.
     */
    public function statusExpectation(): StatusExpectation
    {
        return StatusExpectation::parse($this->expected_status_codes);
    }

    /**
     * Schreibt die nächste Fälligkeit fort — ab `$after`, ersatzweise ab jetzt.
     *
     * Gerechnet wird ab dem Zeitpunkt der Prüfung und nicht ab der geplanten
     * Fälligkeit. Der Unterschied fällt auf, wenn die Anwendung stand: mit der
     * geplanten Zeit als Grundlage holte der Sweep danach jede ausgelassene
     * Minute einzeln nach und feuerte hundert Prüfungen auf ein Ziel, das
     * gerade erst wieder da ist.
     *
     * Gespeichert wird hier nicht — der Aufrufer ändert ohnehin weitere Felder
     * und soll das in einem Schreibvorgang tun können.
     */
    public function scheduleNextCheck(?Carbon $after = null): void
    {
        $this->next_check_at = ($after ?? Carbon::now())->copy()->addSeconds(
            max(self::MINIMUM_INTERVAL_SECONDS, $this->interval_seconds),
        );
    }

    /**
     * Übernimmt den Ausgang einer Prüfung in den Zustand des Monitors.
     *
     * Hier steckt die Schwelle: eine einzelne gescheiterte Prüfung bewegt den
     * Zähler, erklärt aber noch keinen Ausfall. Ob einer beginnt, entscheidet
     * {@see needsOutage()} — und zwar am Zähler, nicht am letzten Messwert.
     *
     * Der Zwischenzustand `degraded` ist der Grund, warum das hier und nicht in
     * einer Zeile am Aufrufer steht: „gescheitert, aber noch kein Ausfall" ist
     * ein eigener Zustand und keine Abwesenheit von einem.
     */
    public function applyCheck(UptimeCheckOutcome $outcome, ?Carbon $at = null): void
    {
        $this->last_checked_at = $at ?? Carbon::now();

        if ($outcome->isFailure()) {
            $this->consecutive_failures++;
            $this->consecutive_successes = 0;

            $this->status = $this->consecutive_failures >= $this->failure_threshold
                ? UptimeStatus::Down
                : UptimeStatus::Degraded;

            return;
        }

        $this->consecutive_successes++;
        $this->consecutive_failures = 0;

        // Zurück auf `up` erst, wenn die Schwelle für die Entwarnung erreicht
        // ist. Sonst zeigte die Übersicht „erreichbar", während der Vorfall
        // noch läuft — und zwei Anzeigen widersprächen einander.
        if ($this->status !== UptimeStatus::Down || $this->consecutive_successes >= $this->recovery_threshold) {
            $this->status = UptimeStatus::Up;
        }
    }

    /**
     * Ist die Schwelle erreicht, ab der ein Ausfall beginnt?
     */
    public function needsOutage(): bool
    {
        return $this->consecutive_failures >= $this->failure_threshold;
    }

    /**
     * Läuft es wieder — genügend oft in Folge, um den Vorfall zu schließen?
     */
    public function needsRecovery(): bool
    {
        return $this->consecutive_successes >= $this->recovery_threshold;
    }

    /**
     * Der Zustand, den die Übersicht zeigt. Ein abgeschalteter Monitor sieht
     * nicht mehr so aus, als sei alles in Ordnung — es wird nur nichts mehr
     * festgestellt.
     */
    public function displayStatus(): UptimeStatus
    {
        return $this->is_active ? $this->status : UptimeStatus::Disabled;
    }

    /**
     * Monitore, deren Prüfung ansteht — der Zugriff des minütlichen Sweeps.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, Carbon $now): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', $now);
    }

    /**
     * @return HasMany<UptimeCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class);
    }

    /**
     * @return HasMany<UptimeOutage, $this>
     */
    public function outages(): HasMany
    {
        return $this->hasMany(UptimeOutage::class);
    }

    /**
     * Der laufende Ausfall, sofern einer läuft.
     *
     * Es gibt je Monitor höchstens einen offenen — der Zustand wird nur an
     * einer Stelle fortgeschrieben ({@see UptimeRecorder}),
     * und die schließt den alten, bevor sie einen neuen öffnet.
     */
    public function openOutage(): ?UptimeOutage
    {
        return $this->outages()
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => HttpMethod::class,
            'headers' => 'array',
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'confirmation_retries' => 'integer',
            'confirmation_delay_seconds' => 'integer',
            'failure_threshold' => 'integer',
            'recovery_threshold' => 'integer',
            'follow_redirects' => 'boolean',
            'verify_tls' => 'boolean',
            'is_active' => 'boolean',
            'status' => UptimeStatus::class,
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }
}
