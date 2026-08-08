<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Merkmal eines Projekts samt Nenner — die Entsprechung zu
 * {@see IssueTagKey} auf der Projekt-Ebene.
 *
 * @property int $id
 * @property int $project_id
 * @property string $tag_key
 * @property int $times_seen
 * @property int $value_count
 */
class ProjectTagKey extends Model
{
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
