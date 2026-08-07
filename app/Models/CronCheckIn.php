<?php

namespace App\Models;

use App\Enums\CronCheckInStatus;
use Database\Factories\CronCheckInFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine einzelne Ausführung eines überwachten Jobs.
 *
 * Sie entsteht auf zwei Wegen: der Job meldet sich (`in_progress`, `ok`,
 * `error`), oder wir stellen fest, dass er sich nicht gemeldet hat (`missed`,
 * `timeout`). Beides landet in derselben Tabelle, denn der Verlauf soll genau
 * das zeigen — die Lücken gehören dazu.
 *
 * @property int $id
 * @property int $cron_monitor_id
 * @property int $project_id
 * @property string|null $check_in_id
 * @property CronCheckInStatus $status
 * @property string|null $environment
 * @property Carbon|null $expected_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property-read CronMonitor $monitor
 */
class CronCheckIn extends Model
{
    /** @use HasFactory<CronCheckInFactory> */
    use HasFactory;

    /**
     * Nimmt eine Meldung des Jobs auf.
     *
     * Kein `Fillable`: die Angaben kommen aus der Aufnahme, und welche Felder
     * zusammengehören (Beginn ohne Ende, Ende mit Dauer) soll der Aufrufer
     * nicht wissen müssen.
     */
    public static function begin(
        CronMonitor $monitor,
        ?string $checkInId = null,
        ?string $environment = null,
        ?Carbon $at = null,
    ): self {
        $at ??= now();

        $entry = new self;

        $entry->cron_monitor_id = $monitor->id;
        $entry->project_id = $monitor->project_id;
        $entry->check_in_id = $checkInId;
        $entry->status = CronCheckInStatus::InProgress;
        $entry->environment = $environment;
        $entry->expected_at = $monitor->next_due_at;
        $entry->started_at = $at;
        $entry->save();

        return $entry;
    }

    /**
     * Schließt eine Ausführung ab.
     *
     * Die gemeldete Dauer hat Vorrang vor der gerechneten: der Job weiß besser,
     * wie lange seine Arbeit gedauert hat, als wir aus zwei Anfragen — dazwischen
     * liegen Netz und Warteschlange. Ohne Angabe rechnen wir aus Start und Ende,
     * und ohne Start bleibt sie leer statt geraten.
     */
    public function finish(CronCheckInStatus $status, ?float $reportedSeconds = null, ?Carbon $at = null): self
    {
        $at ??= now();

        $this->status = $status;
        $this->finished_at = $at;
        $this->duration_ms = self::durationMs($reportedSeconds, $this->started_at, $at);
        $this->save();

        return $this;
    }

    /**
     * Eine Ausführung, die abgeschlossen ankommt, ohne dass ihr Beginn gemeldet
     * wurde — der Regelfall bei kurzen Jobs, die nur „fertig" melden.
     */
    public static function record(
        CronMonitor $monitor,
        CronCheckInStatus $status,
        ?string $checkInId = null,
        ?string $environment = null,
        ?float $reportedSeconds = null,
        ?Carbon $at = null,
        ?Carbon $expectedAt = null,
    ): self {
        $at ??= now();

        $entry = new self;

        $entry->cron_monitor_id = $monitor->id;
        $entry->project_id = $monitor->project_id;
        $entry->check_in_id = $checkInId;
        $entry->status = $status;
        $entry->environment = $environment;
        $entry->expected_at = $expectedAt ?? $monitor->next_due_at;
        $entry->duration_ms = self::durationMs($reportedSeconds, null, $at);

        // Eine verpasste Ausführung hat nie begonnen — ein Startzeitpunkt wäre
        // dort eine Erfindung. Bei allen anderen ist der Beginn aus der
        // gemeldeten Dauer zurückgerechnet, damit der Verlauf nicht so aussieht,
        // als sei alles im selben Augenblick passiert.
        if ($status !== CronCheckInStatus::Missed) {
            $entry->started_at = $entry->duration_ms === null
                ? $at
                : $at->copy()->subMilliseconds($entry->duration_ms);
            $entry->finished_at = $at;
        }

        $entry->save();

        return $entry;
    }

    /**
     * Die offene Ausführung, zu der eine Abschluss-Meldung gehört.
     *
     * Mit Kennung ist die Zuordnung eindeutig. Ohne sie nehmen wir die jüngste
     * offene: ein Job, der „begonnen" und „fertig" ohne Kennung meldet, meint
     * damit denselben Lauf — er kennt gar keine andere Möglichkeit.
     */
    public static function openFor(CronMonitor $monitor, ?string $checkInId): ?self
    {
        $query = $monitor->openCheckIns()->getQuery()->latest('id');

        if ($checkInId !== null) {
            $query->where('check_in_id', $checkInId);
        }

        return $query->first();
    }

    /**
     * Wie lange die Ausführung gebraucht hat, in Millisekunden.
     */
    private static function durationMs(?float $reportedSeconds, ?Carbon $startedAt, Carbon $finishedAt): ?int
    {
        if ($reportedSeconds !== null && $reportedSeconds >= 0) {
            return (int) round($reportedSeconds * 1000);
        }

        if ($startedAt === null) {
            return null;
        }

        // Eine negative Dauer wäre eine schiefe Uhr auf der Gegenseite; dann
        // lieber keine Angabe als eine falsche.
        $elapsed = $startedAt->diffInMilliseconds($finishedAt, false);

        return $elapsed >= 0 ? (int) $elapsed : null;
    }

    /**
     * Die Verspätung gegenüber der geplanten Zeit, in Sekunden. `null`, wenn
     * die Ausführung keinen geplanten Zeitpunkt hat.
     */
    public function delaySeconds(): ?int
    {
        if ($this->expected_at === null || $this->started_at === null) {
            return null;
        }

        return (int) $this->expected_at->diffInSeconds($this->started_at, false);
    }

    /**
     * @return BelongsTo<CronMonitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(CronMonitor::class, 'cron_monitor_id');
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
            'status' => CronCheckInStatus::class,
            'expected_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
