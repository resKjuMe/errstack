<?php

namespace App\Models;

use App\Support\Ingest\Spikes\SpikeGuard;
use App\Support\Ingest\Spikes\SpikeSweep;
use Database\Factories\SpikeProtectionStateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Auslösung des Ausschlag-Schutzes (A7): von wann bis wann gedrosselt
 * wurde, wogegen gemessen wurde und was dabei verworfen wurde.
 *
 * Die **offene** Zeile — `ended_at` ist leer — ist zugleich der laufende
 * Zustand. Kein zusätzlicher Schalter am Projekt: zwei Orte für dieselbe
 * Aussage laufen irgendwann auseinander, und dann drosselt eine Anlage, von der
 * niemand mehr weiß, seit wann.
 *
 * Die Zahlen `peak` und `discarded` schreibt der minütliche Durchlauf fort
 * ({@see SpikeSweep}) und nicht die Aufnahme: ein `increment` je verworfenem
 * Ereignis wäre in der Flut, gegen die gedrosselt wird, genau der Schreibsturm,
 * den die Drosselung verhindern soll.
 *
 * @property int $id
 * @property int $project_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property float $baseline
 * @property int $threshold
 * @property int $peak
 * @property int $discarded
 * @property int|null $released_by_id
 * @property Carbon|null $released_at
 * @property-read Project $project
 * @property-read User|null $releasedBy
 */
class SpikeProtectionState extends Model
{
    /** @use HasFactory<SpikeProtectionStateFactory> */
    use HasFactory;

    /**
     * Die laufende Drosselung eines Projekts, falls es eine gibt.
     */
    public static function open(Project $project): ?self
    {
        return self::query()
            ->where('project_id', $project->id)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Die Drosselung, die in dieser Minute (noch) lief.
     *
     * Nicht dasselbe wie {@see self::open()}: wird von Hand aufgehoben, ist die
     * angebrochene Minute schon verbucht, wenn der Durchlauf sie abholt. Ohne
     * diese Abfrage fielen die letzten Sekunden einer Drosselung aus ihrer
     * eigenen Bilanz — gezählt wären sie, aber am falschen Ort.
     */
    public static function covering(Project $project, Carbon $bucket): ?self
    {
        return self::query()
            ->where('project_id', $project->id)
            ->where('started_at', '<=', $bucket->copy()->endOfMinute())
            ->where(fn (Builder $query) => $query
                ->whereNull('ended_at')
                ->orWhere('ended_at', '>=', $bucket)
            )
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Eröffnet eine Drosselung.
     */
    public static function start(Project $project, float $baseline, int $threshold, int $observed): self
    {
        $state = new self;

        $state->project_id = $project->id;
        $state->started_at = Carbon::now();
        $state->baseline = $baseline;
        $state->threshold = $threshold;
        $state->peak = $observed;
        $state->save();

        return $state;
    }

    /**
     * Beendet die Drosselung.
     *
     * Wer von Hand aufgehoben hat, steht daneben — das ist der Unterschied
     * zwischen „hat sich beruhigt" und „jemand hat entschieden, dass es so
     * weitergehen soll", und beim Nachlesen ist das die erste Frage.
     */
    public function finish(?User $by = null): void
    {
        $this->ended_at = Carbon::now();

        if ($by !== null) {
            $this->released_by_id = $by->id;
            $this->released_at = $this->ended_at;
        }

        $this->save();
    }

    /**
     * Wurde von Hand aufgehoben?
     *
     * Die Frage stellt {@see SpikeGuard}: nach einem Aufheben von Hand gilt eine
     * Ruhefrist, sonst wäre der Knopf wirkungslos — die Flut läuft ja weiter,
     * und die nächste Minute löste sofort wieder aus.
     */
    public function wasReleasedByHand(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * Die jüngsten Auslösungen eines Projekts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query, Project $project): void
    {
        $query->where('project_id', $project->id)->orderByDesc('started_at');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'released_at' => 'datetime',
            'baseline' => 'float',
            'threshold' => 'integer',
            'peak' => 'integer',
            'discarded' => 'integer',
        ];
    }
}
