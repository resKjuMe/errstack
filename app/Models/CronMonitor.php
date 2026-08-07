<?php

namespace App\Models;

use App\Enums\CronCheckInStatus;
use App\Enums\CronIntervalUnit;
use App\Enums\CronMonitorStatus;
use App\Enums\CronScheduleType;
use App\Support\Crons\CronSchedule;
use Database\Factories\CronMonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ein überwachter Cronjob.
 *
 * Der Monitor kennt seinen Zeitplan und den Zustand seiner letzten Ausführung;
 * die Ausführungen selbst stehen im Verlauf ({@see CronCheckIn}). Der Zweck ist
 * die Umkehrung der üblichen Überwachung: gemeldet wird nicht, was passiert,
 * sondern **was ausbleibt**. Ein Job, der nicht läuft, kann sich nicht selbst
 * beschweren.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 * @property CronScheduleType $schedule_type
 * @property string|null $schedule_expression
 * @property int|null $interval_value
 * @property CronIntervalUnit|null $interval_unit
 * @property string $timezone
 * @property int $checkin_margin_minutes
 * @property int $max_runtime_minutes
 * @property int $failure_tolerance
 * @property int $recovery_tolerance
 * @property bool $is_active
 * @property CronMonitorStatus $status
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property Carbon|null $last_check_in_at
 * @property Carbon|null $next_due_at
 * @property Carbon|null $alerted_at
 * @property-read Project $project
 */
#[Fillable([
    'name',
    'schedule_type',
    'schedule_expression',
    'interval_value',
    'interval_unit',
    'timezone',
    'checkin_margin_minutes',
    'max_runtime_minutes',
    'failure_tolerance',
    'recovery_tolerance',
    'is_active',
])]
class CronMonitor extends Model
{
    /** @use HasFactory<CronMonitorFactory> */
    use HasFactory;

    /**
     * Wie viele Ausführungen die Übersicht zeigt. Der Verlauf soll die Frage
     * „lief das zuletzt sauber?" beantworten, nicht ein Archiv sein.
     */
    public const HISTORY_LIMIT = 20;

    /**
     * In der Adresszeile steht die Kennung, nicht die Nummer
     * (`…/projekte/{projekt}/cronjobs/{cronjob}`) — dieselbe Kennung, die im
     * Check-in steht. So zeigt der Link aus einer Alarm-Meldung auf etwas, das
     * man wiedererkennt.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Legt einen Monitor an und setzt gleich seinen ersten Termin.
     *
     * Benannter Konstruktor wie bei Projekt und Schlüssel: `next_due_at` ergibt
     * sich aus dem Zeitplan und darf nicht vom Aufrufer kommen — ein Monitor
     * ohne Termin wäre für die Prüfung unsichtbar und würde nie einen Ausfall
     * melden.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createFor(Project $project, string $name, array $attributes = [], ?string $slug = null): self
    {
        $monitor = new self($attributes + ['name' => $name]);

        $monitor->project_id = $project->id;
        $monitor->slug = self::uniqueSlug($project, $slug ?? $name);
        $monitor->status = CronMonitorStatus::Unknown;
        $monitor->save();

        // Erst nachladen, dann rechnen: was der Aufrufer nicht mitgegeben hat
        // (Zeitplan-Art, Zeitzone, Toleranz), steht bis hierher nur als Vorgabe
        // in der Tabelle und nicht am Objekt. Ohne diesen Schritt rechnete
        // `scheduleNextDue()` mit lauter `null`.
        $monitor->refresh();

        $monitor->scheduleNextDue();
        $monitor->save();

        return $monitor;
    }

    /**
     * Sprechende Kennung innerhalb des Projekts. Sie steht im Job und damit in
     * fremdem Code; eine spätere Umbenennung des Monitors lässt sie deshalb
     * absichtlich unangetastet — sonst hörten die Check-ins auf anzukommen.
     */
    public static function uniqueSlug(Project $project, string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'cronjob';
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

    public static function findBySlug(Project $project, string $slug): ?self
    {
        return self::query()
            ->where('project_id', $project->id)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Der Zeitplan als Wert. Steht in der Spalte Unsinn — ein Ausdruck, den
     * eine spätere Fassung der Bibliothek nicht mehr liest —, kommt `null`
     * zurück; die Prüfung überspringt den Monitor dann, statt zu scheitern.
     */
    public function schedule(): ?CronSchedule
    {
        try {
            return $this->schedule_type === CronScheduleType::Crontab
                ? CronSchedule::crontab((string) $this->schedule_expression, $this->timezone)
                : CronSchedule::interval((int) $this->interval_value, $this->interval_unit ?? CronIntervalUnit::Minute, $this->timezone);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Schreibt den nächsten Termin fort — ab `$after`, ersatzweise ab jetzt.
     *
     * Gespeichert wird hier nicht: der Aufrufer ändert meist ohnehin weitere
     * Felder und soll das in einem Schreibvorgang tun können.
     */
    public function scheduleNextDue(?Carbon $after = null): void
    {
        $this->next_due_at = $this->schedule()?->nextAfter($after ?? now());
    }

    /**
     * Der Zeitpunkt, ab dem eine ausgebliebene Ausführung als verpasst gilt:
     * geplante Zeit plus Toleranzfenster.
     */
    public function dueDeadline(): ?Carbon
    {
        return $this->next_due_at?->copy()->addMinutes($this->checkin_margin_minutes);
    }

    /**
     * Übernimmt den Ausgang einer Ausführung in den Zustand des Monitors.
     *
     * Hier steckt die Fehlertoleranz: ein einzelner Fehlschlag ändert den
     * Zustand, löst aber noch keinen Alarm aus. Ob einer rausgeht, entscheidet
     * {@see needsAlert()} — und zwar am Zähler, nicht am letzten Ergebnis.
     */
    public function applyCheckIn(CronCheckInStatus $status, ?Carbon $at = null): void
    {
        $at ??= now();

        $this->status = CronMonitorStatus::fromCheckIn($status);
        $this->last_check_in_at = $at;

        if ($status === CronCheckInStatus::InProgress) {
            // Ein begonnener Lauf ist noch kein Ergebnis und darf keinen der
            // beiden Zähler bewegen.
            return;
        }

        if ($status->isFailure()) {
            $this->consecutive_failures++;
            $this->consecutive_successes = 0;

            return;
        }

        $this->consecutive_successes++;
        $this->consecutive_failures = 0;
    }

    /**
     * Ist die Fehlertoleranz aufgebraucht und noch kein Alarm raus?
     */
    public function needsAlert(): bool
    {
        return $this->alerted_at === null
            && $this->consecutive_failures >= $this->failure_tolerance;
    }

    /**
     * Läuft es wieder — und lief zuvor ein Alarm?
     */
    public function needsRecovery(): bool
    {
        return $this->alerted_at !== null
            && $this->consecutive_successes >= $this->recovery_tolerance;
    }

    /**
     * Der Zustand, den die Übersicht zeigt. Ein abgeschalteter Monitor sieht
     * nicht mehr so aus, als sei alles in Ordnung — es wird nur nichts mehr
     * festgestellt.
     */
    public function displayStatus(): CronMonitorStatus
    {
        return $this->is_active ? $this->status : CronMonitorStatus::Disabled;
    }

    /**
     * Monitore, deren Termin samt Toleranz verstrichen ist — der Zugriff der
     * minütlichen Prüfung.
     *
     * Das Toleranzfenster steht in einer Spalte und lässt sich deshalb nicht
     * ohne Weiteres in SQL addieren; abgezogen wird es stattdessen von der
     * Gegenseite. Die Grenze ist großzügig gewählt und nur eine Vorauswahl —
     * die genaue Entscheidung fällt danach in PHP.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, Carbon $now): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', $now);
    }

    /**
     * @return HasMany<CronCheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CronCheckIn::class);
    }

    /**
     * Die laufende Ausführung, sofern eine begonnen und nicht abgeschlossen
     * wurde.
     *
     * @return HasMany<CronCheckIn, $this>
     */
    public function openCheckIns(): HasMany
    {
        return $this->checkIns()->where('status', CronCheckInStatus::InProgress);
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
            'schedule_type' => CronScheduleType::class,
            'interval_unit' => CronIntervalUnit::class,
            'interval_value' => 'integer',
            'checkin_margin_minutes' => 'integer',
            'max_runtime_minutes' => 'integer',
            'failure_tolerance' => 'integer',
            'recovery_tolerance' => 'integer',
            'is_active' => 'boolean',
            'status' => CronMonitorStatus::class,
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_check_in_at' => 'datetime',
            'next_due_at' => 'datetime',
            'alerted_at' => 'datetime',
        ];
    }
}
