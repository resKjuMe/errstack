<?php

namespace App\Models;

use App\Enums\PerformanceProblem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Die Abweichung eines Projekts von den Vorgaben eines Erkenners.
 *
 * Es gibt keine Zeile für den Normalfall. Ein Projekt, das nichts eingestellt
 * hat, hat hier nichts stehen — und bekommt damit die Vorgabewerte aus
 * {@see PerformanceProblem::defaults()}, auch die, die es beim Anlegen des
 * Projekts noch gar nicht gab.
 *
 * Das ist der ganze Grund für die Bauart: eine Zeile je Projekt und Muster
 * vorab anzulegen wäre bequemer zu lesen, würde aber jeden späteren besseren
 * Vorgabewert an den bestehenden Projekten vorbeilaufen lassen. Wer nie etwas
 * eingestellt hat, soll die bessere Vorgabe bekommen; wer etwas eingestellt
 * hat, seinen Wert behalten.
 *
 * @property int $id
 * @property int $project_id
 * @property PerformanceProblem $problem
 * @property bool $is_enabled
 * @property array<string, int>|null $thresholds
 */
class PerformanceSetting extends Model
{
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected $fillable = [
        'project_id',
        'problem',
        'is_enabled',
        'thresholds',
    ];

    protected function casts(): array
    {
        return [
            'problem' => PerformanceProblem::class,
            'is_enabled' => 'boolean',
            'thresholds' => 'array',
        ];
    }
}
