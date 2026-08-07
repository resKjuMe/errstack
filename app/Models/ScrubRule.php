<?php

namespace App\Models;

use App\Enums\ScrubRuleType;
use App\Support\Ingest\Scrubbing\Directive;
use Database\Factories\ScrubRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine eigene Datenschutz-Regel.
 *
 * Sie gehört entweder einem Projekt oder — mit leerem `project_id` — der
 * Organisation und gilt dann für alle deren Projekte. Beides in einem Model,
 * weil beides dieselbe Regel ist: der Unterschied ist ihre Reichweite, nicht
 * ihre Wirkung.
 *
 * Was die Regel bei der Aufnahme tut, steht nicht hier, sondern in
 * {@see Directive} — dieses Model ist die Ablage, die Auswertung läuft ohne
 * Datenbank und ist damit ohne Aufbau eines Projekts prüfbar.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $project_id
 * @property ScrubRuleType $type
 * @property string $expression
 * @property string|null $path
 * @property bool $is_active
 * @property-read Organization $organization
 * @property-read Project|null $project
 */
#[Fillable(['type', 'expression', 'path', 'is_active'])]
class ScrubRule extends Model
{
    /** @use HasFactory<ScrubRuleFactory> */
    use HasFactory;

    /**
     * Gilt die Regel für alle Projekte ihrer Organisation?
     */
    public function isOrganizationWide(): bool
    {
        return $this->project_id === null;
    }

    /**
     * Die Regel in der Form, in der die Aufnahme mit ihr arbeitet.
     */
    public function toDirective(): Directive
    {
        return new Directive($this->type, $this->expression, $this->path);
    }

    /**
     * Die Regeln, die für dieses Projekt gelten: seine eigenen und die
     * organisationsweiten.
     *
     * Ein Zugriff für beide Ebenen, weil sie in der Aufnahme nie getrennt
     * gebraucht werden — und zwei Abfragen je Meldung wären bei einer
     * Fehlerflut zwei zu viel.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEffectiveFor(Builder $query, Project $project): void
    {
        $query
            ->where('organization_id', $project->organization_id)
            ->where(fn (Builder $scope) => $scope
                ->whereNull('project_id')
                ->orWhere('project_id', $project->id));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
            'type' => ScrubRuleType::class,
            'is_active' => 'boolean',
        ];
    }
}
