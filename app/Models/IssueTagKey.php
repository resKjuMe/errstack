<?php

namespace App\Models;

use App\Support\Tags\TagAggregates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Merkmal eines Fehler-Eintrags samt Nenner — „von diesem Fehler haben
 * 1.204 Ereignisse einen Browser gemeldet".
 *
 * Der Zähler sieht überflüssig aus, solange man die Werte summieren könnte. Kann
 * man aber nicht: sie sind je Merkmal begrenzt
 * ({@see TagAggregates::MAX_VALUES_PER_KEY}), und ihre Summe
 * ist deshalb kleiner als die Zahl der Ereignisse, sobald ein Merkmal mehr Werte
 * hatte als aufgehoben werden. Ohne eigenen Nenner käme „100 %" heraus, obwohl
 * ein Teil fehlt.
 *
 * @property int $id
 * @property int $issue_id
 * @property int $project_id
 * @property string $tag_key
 * @property int $times_seen
 * @property int $value_count
 */
class IssueTagKey extends Model
{
    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'issue_id',
        'project_id',
        'tag_key',
        'times_seen',
        'value_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times_seen' => 'integer',
            'value_count' => 'integer',
        ];
    }
}
