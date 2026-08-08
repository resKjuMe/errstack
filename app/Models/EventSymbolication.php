<?php

namespace App\Models;

use App\Enums\SymbolicationStatus;
use App\Jobs\SymbolicateEvent;
use App\Support\SourceMaps\SymbolicationResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Der zurückübersetzte Stacktrace einer Meldung.
 *
 * Eine Zeile je Meldung, und sie ist zweierlei: die **zusätzliche Sicht** neben
 * dem, was das SDK gemeldet hat, und der **Zwischenspeicher** für eine Rechnung,
 * die eine mehrere Megabyte große Quellkarte einliest. Beides steht in der
 * Migration begründet.
 *
 * Angelegt wird die Zeile vom Auftrag im Hintergrund ({@see SymbolicateEvent}) —
 * zuerst als Platzhalter mit {@see SymbolicationStatus::Pending}, damit die
 * Anzeige „wird übersetzt" sagen kann und nicht „nicht möglich", und danach mit
 * dem Ergebnis.
 *
 * @property int $id
 * @property int $event_id
 * @property int $project_id
 * @property SymbolicationStatus $status
 * @property list<array<string, mixed>>|null $exceptions
 * @property list<array{reason: string, detail: string|null, count: int}>|null $diagnostics
 * @property int $mapped_frames
 * @property int $total_frames
 * @property int|null $duration_ms
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class EventSymbolication extends Model
{
    /**
     * Merkt vor, dass für diese Meldung übersetzt wird.
     *
     * `createOrFirst()` und nicht „nachsehen, dann anlegen": die Vormerkung ist
     * genau der Ort, an dem sich zwei Anläufe treffen — der Auftrag aus der
     * Aufnahme und der aus einer aufgeschlagenen Fehlerseite. Wer verliert,
     * bekommt die Zeile des Gewinners und weiß damit, dass er nichts zu tun hat.
     *
     * @return array{self, bool} Die Zeile und ob sie gerade entstanden ist.
     */
    public static function reserve(Event $event): array
    {
        $record = self::query()->createOrFirst(
            ['event_id' => $event->id],
            [
                'project_id' => $event->project_id,
                'status' => SymbolicationStatus::Pending,
            ],
        );

        return [$record, $record->wasRecentlyCreated];
    }

    /**
     * Schreibt das Ergebnis in die vorgemerkte Zeile.
     */
    public function complete(SymbolicationResult $result, int $durationMs): void
    {
        $this->forceFill([
            'status' => $result->status,
            'exceptions' => $result->exceptions === [] ? null : $result->exceptions,
            'diagnostics' => $result->diagnostics() === [] ? null : $result->diagnostics(),
            'mapped_frames' => $result->mappedFrames,
            'total_frames' => $result->totalFrames,
            'duration_ms' => $durationMs,
        ])->save();
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'project_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SymbolicationStatus::class,
            'exceptions' => 'array',
            'diagnostics' => 'array',
            'mapped_frames' => 'integer',
            'total_frames' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
