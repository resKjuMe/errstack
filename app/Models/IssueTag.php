<?php

namespace App\Models;

use App\Support\Tags\TagAggregates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Merkmalswert eines Fehler-Eintrags samt Zähler — „dieser Fehler trat
 * 412 mal in Chrome 124 auf".
 *
 * Die Zeile entsteht beim Eingang und wird dort auch fortgeschrieben; gelesen
 * wird sie von der Merkmal-Ansicht und vom Filter der Fehlerliste. Das Modell
 * selbst kann deshalb bewusst wenig: geschrieben wird über
 * {@see TagAggregates}, und zwar in rohem SQL, weil jede
 * Fortschreibung sperrfrei sein muss.
 *
 * @property int $id
 * @property int $issue_id
 * @property int $project_id
 * @property string $tag_key
 * @property string $tag_value
 * @property int $times_seen
 * @property CarbonImmutable $first_seen
 * @property CarbonImmutable $last_seen
 */
class IssueTag extends Model
{
    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'issue_id',
        'project_id',
        'tag_key',
        'tag_value',
        'times_seen',
        'first_seen',
        'last_seen',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times_seen' => 'integer',
            'first_seen' => 'immutable_datetime',
            'last_seen' => 'immutable_datetime',
        ];
    }
}
