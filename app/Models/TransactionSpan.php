<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Einzelschritt innerhalb einer Transaktion: die Datenbankabfrage, der
 * Aufruf eines fremden Dienstes, das Zeichnen der Vorlage.
 *
 * Die Spalte `parent_span_id` ist der eigentliche Inhalt dieser Tabelle. Ohne
 * sie wäre eine Transaktion eine Liste von Dauern, die zusammen mehr ergeben als
 * die Transaktion selbst — weil Schritte ineinander liegen. Erst die
 * Eltern-Kind-Beziehung macht daraus die Aussage „von den 4 Sekunden gingen 3,6
 * in diese eine Abfrage".
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $project_id
 * @property string $trace_id
 * @property string $span_id
 * @property string|null $parent_span_id
 * @property string|null $op
 * @property string|null $description
 * @property string|null $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $finished_at
 * @property int $duration_us
 * @property array<string, mixed>|null $data
 * @property int $position
 */
class TransactionSpan extends Model
{
    /**
     * Mit Millisekunden, wie bei der Transaktion ({@see Transaction::$dateFormat}).
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Längstmögliche Beschreibung.
     *
     * Groß, weil dort das SQL einer Abfrage steht — und eine bei 255 Zeichen
     * abgeschnittene Abfrage benennt das Problem nicht mehr, das sie ist. Die
     * Spalte selbst ist `text`; diese Grenze schützt nur davor, dass ein SDK mit
     * einer megabytegroßen Beschreibung die Zeile sprengt.
     */
    public const DESCRIPTION_LIMIT = 8192;

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_us' => 'integer',
            'position' => 'integer',
            'data' => 'array',
        ];
    }
}
