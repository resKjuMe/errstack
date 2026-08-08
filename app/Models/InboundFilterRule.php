<?php

namespace App\Models;

use App\Enums\InboundFilterKind;
use App\Support\Ingest\Filtering\InboundFilter;
use Database\Factories\InboundFilterRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Eintrag in der Liste eines Eingangsfilters.
 *
 * Was der Ausdruck bedeutet, hängt an der Art: bei einem Fehlertext ist es ein
 * Muster mit `*`, bei einem Absender eine Adresse oder ein Netz, bei einem
 * Release ein Name (wieder mit `*`), bei einem Browser eine Untergrenze
 * (`safari:6`). Ausgewertet wird das nicht hier, sondern in
 * {@see InboundFilter} — dieses Model ist die Ablage, und die Auswertung soll
 * ohne Datenbank prüfbar bleiben.
 *
 * @property int $id
 * @property int $project_id
 * @property InboundFilterKind $kind
 * @property string $expression
 * @property bool $is_active
 * @property-read Project $project
 */
#[Fillable(['kind', 'expression', 'is_active'])]
class InboundFilterRule extends Model
{
    /** @use HasFactory<InboundFilterRuleFactory> */
    use HasFactory;

    /**
     * Wie viele Einträge ein Projekt je Filterart haben darf.
     *
     * Die Grenze schützt die Aufnahme und nicht die Tabelle: jeder Eintrag ist
     * ein Vergleich für **jede** eingehende Meldung, und eine Liste, die
     * jemand aus einem Protokoll hineinkopiert hat, wäre bei einer Fehlerflut
     * genau die Bremse, die dieser Filter vermeiden soll.
     */
    public const MAX_PER_KIND = 100;

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
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
            'kind' => InboundFilterKind::class,
            'is_active' => 'boolean',
        ];
    }
}
