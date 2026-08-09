<?php

namespace App\Models;

use App\Support\Replays\ReplayStore;
use Carbon\CarbonImmutable;
use Database\Factories\ReplaySegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Abschnitt einer Aufzeichnung — ein Stück Film von einigen Sekunden.
 *
 * Das SDK schneidet die Aufnahme in Abschnitte und schickt sie einzeln, statt am
 * Ende der Sitzung alles auf einmal. Das ist die einzige Form, in der eine
 * Aufzeichnung überhaupt ankommen kann: eine Sitzung endet oft mit dem Schließen
 * der Registerkarte, und was bis dahin nicht gesendet wurde, ist verloren.
 *
 * **Hier steht nicht der Film, sondern wo er liegt.** Die Bilddaten sind gepackt
 * auf einer Platte ({@see ReplayStore}); diese Zeile trägt Pfad, Größe und
 * Zeitspanne. Der Grund steht in der Migration: es sind Megabyte, sie werden nur
 * als Ganzes gelesen, und sie sollen sich getrennt von den Ereignisdaten
 * löschen lassen.
 *
 * @property int $id
 * @property int $replay_id
 * @property int $project_id
 * @property int|null $ingest_payload_id
 * @property int $segment_id
 * @property string $path
 * @property int $size_bytes
 * @property int $event_count
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $ended_at
 */
class ReplaySegment extends Model
{
    /** @use HasFactory<ReplaySegmentFactory> */
    use HasFactory;

    /**
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Die Sitzung, zu der dieser Abschnitt gehört.
     *
     * @return BelongsTo<Replay, $this>
     */
    public function replay(): BelongsTo
    {
        return $this->belongsTo(Replay::class);
    }

    /**
     * Die Rohdaten, aus denen der Abschnitt entstand — sofern sie noch da sind.
     *
     * @return BelongsTo<IngestPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(IngestPayload::class, 'ingest_payload_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'segment_id' => 'integer',
            'size_bytes' => 'integer',
            'event_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
