<?php

namespace App\Models;

use App\Enums\UptimeCheckOutcome;
use App\Support\Uptime\ProbeResult;
use Database\Factories\UptimeOutageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ein Ausfall: die Zeitspanne, in der ein Ziel nicht erreichbar war.
 *
 * Der Vorfall, über den geredet wird. Eine einzelne gescheiterte Messung ist
 * eine Zeile im Verlauf; ein Ausfall ist das, was in der Nachbesprechung steht,
 * und deshalb trägt er Beginn, Ende und Dauer als eigene Angaben.
 *
 * **Das Ende ist der Kern der Klasse.** Ein Ausfall ohne Ende ist der laufende
 * — genau daran wird er gefunden ({@see UptimeMonitor::openOutage()}), und
 * genau deshalb darf es je Monitor höchstens einen davon geben.
 *
 * @property int $id
 * @property int $uptime_monitor_id
 * @property int $project_id
 * @property int|null $issue_id
 * @property UptimeCheckOutcome $outcome
 * @property int|null $http_status
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property int $failed_checks
 * @property-read UptimeMonitor $monitor
 * @property-read Issue|null $issue
 */
class UptimeOutage extends Model
{
    /** @use HasFactory<UptimeOutageFactory> */
    use HasFactory;

    /**
     * Eröffnet einen Ausfall.
     *
     * Der Beginn ist der Zeitpunkt der Messung, die ihn ausgelöst hat, und
     * nicht „jetzt". Bei einer Schwelle über mehreren Messungen sind das zwei
     * verschiedene Angaben; genommen wird die, die der Aufrufer übergibt — er
     * weiß, welche Messung gemeint ist.
     */
    public static function open(UptimeMonitor $monitor, ProbeResult $result, Carbon $at): self
    {
        return self::query()->create([
            'uptime_monitor_id' => $monitor->id,
            'project_id' => $monitor->project_id,
            'outcome' => $result->outcome,
            'http_status' => $result->httpStatus,
            'error' => $result->error === null ? null : Str::limit($result->error, UptimeCheck::ERROR_LIMIT, ''),
            'started_at' => $at,
            'failed_checks' => 1,
        ]);
    }

    /**
     * Zählt eine weitere gescheiterte Messung zum laufenden Ausfall.
     *
     * Sperrfrei wie die Zähler am Fehler-Eintrag: die Datenbank setzt den alten
     * Wert selbst ein. Der Grund ist hier ein anderer als dort — nicht die Last,
     * sondern die Nebenläufigkeit zweier Arbeiter, die denselben Monitor
     * gleichzeitig prüfen, weil ein Durchlauf länger als ein Takt gebraucht hat.
     */
    public function noteFailure(): void
    {
        DB::update(
            'update '.$this->getTable().' set failed_checks = failed_checks + 1, updated_at = ? where id = ?',
            [Carbon::now()->format('Y-m-d H:i:s'), $this->id],
        );

        $this->failed_checks++;
    }

    /**
     * Schließt den Ausfall ab.
     *
     * Die Dauer wird hier gerechnet und gespeichert, statt sie bei jeder
     * Anzeige aus den beiden Zeitpunkten zu bilden: sie steht in Meldungen, in
     * der Liste und in Auswertungen, und nach ihr wird sortiert.
     *
     * Der Abschluss ist an `ended_at is null` gebunden. Zwei Arbeiter, die
     * gleichzeitig Entwarnung feststellen, dürfen den Vorfall nicht zweimal
     * beenden — der zweite bekäme sonst eine andere Dauer und überschriebe die
     * erste.
     *
     * @return bool `false`, wenn ihn bereits jemand geschlossen hatte.
     */
    public function close(Carbon $at): bool
    {
        $duration = max(0, $at->diffInSeconds($this->started_at, absolute: true));

        $closed = self::query()
            ->whereKey($this->id)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => $at,
                'duration_seconds' => $duration,
                'updated_at' => Carbon::now(),
            ]);

        if ($closed !== 1) {
            return false;
        }

        $this->ended_at = $at;
        $this->duration_seconds = $duration;

        return true;
    }

    /**
     * Wie lange der Ausfall dauert bzw. gedauert hat, in Sekunden.
     *
     * Für den laufenden Vorfall gerechnet, für den abgeschlossenen abgelesen —
     * die Anzeige stellt nicht zwei Fragen, sondern eine.
     */
    public function duration(?Carbon $now = null): int
    {
        if ($this->duration_seconds !== null) {
            return $this->duration_seconds;
        }

        return max(0, ($now ?? Carbon::now())->diffInSeconds($this->started_at, absolute: true));
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @return BelongsTo<UptimeMonitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'uptime_monitor_id');
    }

    /**
     * Der Fehler-Eintrag zu diesem Ausfall.
     *
     * `null`, wenn er nie angelegt werden konnte oder jemand ihn gelöscht hat —
     * dass die Seite weg war, bleibt trotzdem wahr.
     *
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => UptimeCheckOutcome::class,
            'http_status' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'failed_checks' => 'integer',
        ];
    }
}
