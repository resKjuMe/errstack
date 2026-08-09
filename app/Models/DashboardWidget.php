<?php

namespace App\Models;

use App\Enums\WidgetType;
use App\Support\Dashboards\WidgetOverrides;
use App\Support\Dashboards\WidgetQuery;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Kachel: eine Frage, eine Darstellungsart und eine Lage im Raster.
 *
 * **Sie speichert ihre Abfrage, nicht ihre Daten.** Das ist die Zusage des
 * ganzen Dashboards (die Begründung steht in der Migration), und sie ist der
 * Grund, warum hier keine Spalte für ein Ergebnis oder einen Zeitpunkt der
 * letzten Berechnung steht. Was die Kachel zeigt, entsteht beim Aufschlagen aus
 * {@see WidgetQuery} — und ist damit so aktuell wie die Zahl daneben in der
 * freien Auswertung.
 *
 * @property int $id
 * @property int $dashboard_id
 * @property string $title
 * @property WidgetType $type
 * @property array<string, mixed> $query
 * @property array<string, mixed>|null $overrides
 * @property int $x
 * @property int $y
 * @property int $width
 * @property int $height
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'dashboard_id',
    'title',
    'type',
    'query',
    'overrides',
    'x',
    'y',
    'width',
    'height',
])]
class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;

    /** Längste Überschrift — sie steht in einer Kachelzeile und nicht darunter. */
    public const TITLE_LIMIT = 120;

    /**
     * @return BelongsTo<Dashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * Die gespeicherte Abfrage als Gegenstand — nachsichtig gelesen, damit eine
     * Kachel ein fortgefallenes Feld überlebt.
     */
    public function widgetQuery(): WidgetQuery
    {
        return WidgetQuery::fromArray($this->query);
    }

    /**
     * Was diese Kachel an der Filterleiste anders sieht.
     */
    public function widgetOverrides(): WidgetOverrides
    {
        return WidgetOverrides::fromArray($this->overrides);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WidgetType::class,
            'query' => 'array',
            'overrides' => 'array',
        ];
    }
}
