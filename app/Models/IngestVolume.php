<?php

namespace App\Models;

use App\Support\Ingest\Spikes\SpikeBaseline;
use App\Support\Ingest\Spikes\SpikeCounter;
use App\Support\Ingest\Spikes\SpikeSweep;
use Database\Factories\IngestVolumeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Wie viele Ereignisse ein Projekt in einer Minute gemeldet hat.
 *
 * Der Verlauf ist die Grundlage des Ausschlag-Schutzes (A7): erst er macht aus
 * „viele Ereignisse" die Aussage „ungewöhnlich viele". Ein fester Absolutwert
 * wäre für jedes Projekt der falsche — zehntausend Meldungen je Minute sind bei
 * der einen Anwendung Normalbetrieb und bei der anderen der Vorfall.
 *
 * Geschrieben wird eine Zeile je Projekt und Minute, und zwar vom minütlichen
 * Durchlauf ({@see SpikeSweep}) aus dem Zähler im Zwischenspeicher
 * ({@see SpikeCounter}). Während der Aufnahme wird hier **nichts** geschrieben:
 * eine Zeile je Ereignis wäre ausgerechnet in der Flut, gegen die der Schutz
 * gedacht ist, deren größter Verstärker.
 *
 * @property int $id
 * @property int $project_id
 * @property Carbon $bucket
 * @property int $quantity
 * @property bool $throttled
 */
class IngestVolume extends Model
{
    /** @use HasFactory<IngestVolumeFactory> */
    use HasFactory;

    /**
     * Wie lange der Verlauf aufbewahrt wird.
     *
     * Sieben Tage: die Auswertung sieht höchstens Stunden zurück
     * ({@see SpikeBaseline::HISTORY_MINUTES}), die Seite zeigt den Tag. Was
     * darüber hinausgeht, ist eine Zeile je Projekt und Minute, die niemand mehr
     * liest — bei tausend Projekten sind das zehn Millionen Zeilen im Monat.
     */
    public const RETAINED_DAYS = 7;

    /**
     * Die Minute, in der gezählt wird.
     *
     * Feiner als bei den Verwerfungen (dort genügt die Stunde), weil eine
     * Fehlerflut in Minuten gemessen wird: eine fehlerhafte Auslieferung
     * erzeugt ihre Millionen Meldungen nicht gleichmäßig über eine Stunde
     * verteilt, und eine Stundensumme sagt genau dann nichts, wenn es darauf
     * ankommt.
     */
    public static function bucket(?Carbon $at = null): Carbon
    {
        return ($at?->copy() ?? Carbon::now())->startOfMinute();
    }

    /**
     * Schreibt die Menge einer abgeschlossenen Minute fest.
     *
     * Vorhandenes wird überschrieben und nicht ergänzt: läuft der Zeitplan
     * doppelt an — ein nachgeholter Durchlauf, zwei Server ohne
     * `withoutOverlapping` —, ist dieselbe Minute schon geschrieben. Die zweite
     * Ausführung ist dann eine Wiederholung derselben Messung und keine zweite.
     */
    public static function record(Project $project, Carbon $bucket, int $quantity, bool $throttled): self
    {
        $volume = self::query()
            ->where('project_id', $project->id)
            ->where('bucket', $bucket)
            ->first() ?? new self;

        $volume->project_id = $project->id;
        $volume->bucket = $bucket;
        $volume->quantity = max(0, $quantity);
        $volume->throttled = $throttled;
        $volume->save();

        return $volume;
    }

    /**
     * Der Verlauf eines Projekts, jüngste Minute zuerst.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecent(Builder $query, Project $project, int $minutes): void
    {
        $query
            ->where('project_id', $project->id)
            ->orderByDesc('bucket')
            ->limit($minutes);
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
            'bucket' => 'datetime',
            'quantity' => 'integer',
            'throttled' => 'boolean',
        ];
    }
}
