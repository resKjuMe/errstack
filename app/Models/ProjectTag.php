<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Merkmalswert eines Projekts samt Zähler — „in diesem Projekt kamen
 * 18.402 Fehlermeldungen aus Chrome 124".
 *
 * Dieselbe Zeile eine Ebene höher als {@see IssueTag}, und sie wird getrennt
 * geschrieben statt aus den Fehler-Einträgen gerechnet: „welche Browser sind in
 * diesem Projekt betroffen" wäre dort eine Gruppierung über alle Zeilen aller
 * Fehler — genau der Volltabellen-Scan, den die Vorberechnung vermeiden soll.
 *
 * @property int $id
 * @property int $project_id
 * @property string $tag_key
 * @property string $tag_value
 * @property int $times_seen
 * @property CarbonImmutable $first_seen
 * @property CarbonImmutable $last_seen
 */
class ProjectTag extends Model
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
