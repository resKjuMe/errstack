<?php

namespace App\Models;

use App\Policies\DashboardPolicy;
use App\Support\Dashboards\DashboardLayout;
use Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ein Dashboard: eine benannte Sammlung von Kacheln in einer Anordnung.
 *
 * **Es ist eine Sammlung von Fragen, kein gespeicherter Bildschirm.** Jede
 * Kachel trägt ihre Abfrage; gerechnet wird beim Aufschlagen. Der Zeitraum, die
 * Umgebung und die Projektauswahl gehören weiterhin der Filterleiste — dasselbe
 * Verhältnis wie bei den gespeicherten Suchen (S6). Wer ein Dashboard über den
 * letzten 30 Tagen des Webshops aufschlägt, sieht seine Kacheln über den letzten
 * 30 Tagen des Webshops. Die Ausnahme ist ausdrücklich und liegt an der Kachel,
 * nicht hier ({@see DashboardWidget::$overrides}).
 *
 * **Freigeben heißt sehen, nicht ändern** — wie bei der gespeicherten Suche
 * ({@see DashboardPolicy}). Wer ein fremdes Dashboard als Ausgangspunkt braucht,
 * dupliziert es und hat danach seines.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $name
 * @property string $description
 * @property bool $shared
 * @property string|null $template
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, DashboardWidget> $widgets
 * @property-read int|null $widgets_count
 */
#[Fillable([
    'organization_id',
    'user_id',
    'name',
    'description',
    'shared',
    'template',
])]
class Dashboard extends Model
{
    /** @use HasFactory<DashboardFactory> */
    use HasFactory;

    /** Längster Name — kurz genug, dass die Liste eine Liste bleibt. */
    public const NAME_LIMIT = 80;

    /** Längste Beschreibung: ein Satz, keine Anleitung. */
    public const DESCRIPTION_LIMIT = 500;

    /**
     * Wie viele Dashboards ein Konto je Organisation anlegen darf.
     *
     * Dieselbe Größenordnung wie bei den gespeicherten Suchen und aus demselben
     * Grund: die Liste soll sich überblicken lassen.
     */
    public const MAX_PER_USER = 50;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Die Kacheln in Leserichtung: von oben nach unten, in einer Zeile von
     * links nach rechts.
     *
     * Die Reihenfolge steckt in der Beziehung und nicht in jedem Aufrufer: sie
     * ist die Reihenfolge der Tastatur-Navigation und die des Duplikats, und
     * beide sollen mit dem übereinstimmen, was man sieht.
     *
     * @return HasMany<DashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('y')->orderBy('x')->orderBy('id');
    }

    /**
     * Ist an diesem Dashboard noch Platz für eine Kachel?
     */
    public function hasRoomForWidget(): bool
    {
        return $this->widgets()->count() < DashboardLayout::MAX_WIDGETS;
    }

    /**
     * Was ein Betrachter in dieser Organisation sieht: seine eigenen und die
     * freigegebenen.
     *
     * Als Scope und nicht als Prüfung je Zeile — die Liste ist eine Abfrage.
     *
     * @param  Builder<Dashboard>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer, Organization $organization): void
    {
        $query
            ->where('organization_id', $organization->id)
            ->where(function (Builder $inner) use ($viewer): void {
                $inner->where('user_id', $viewer->id)->orWhere('shared', true);
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shared' => 'boolean',
        ];
    }
}
