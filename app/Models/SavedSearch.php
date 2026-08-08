<?php

namespace App\Models;

use App\Enums\IssueSort;
use App\Http\Requests\IssueListRequest;
use App\Policies\SavedSearchPolicy;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eine gespeicherte Suche: ein Suchausdruck und eine Sortierung unter einem
 * Namen.
 *
 * **Sie ist kein gespeicherter Seitenzustand.** Zeitraum, Projektauswahl und
 * Umgebung stehen ausdrücklich nicht darin; sie gehören der globalen
 * Filterleiste und bleiben beim Anwenden unangetastet (die Begründung steht in
 * der Migration). Wer „Kritische offene Fehler" aufruft, während er die letzten
 * 30 Tage des Webshops untersucht, bekommt die kritischen offenen Fehler der
 * letzten 30 Tage des Webshops — und nicht einen anderen Zeitraum.
 *
 * **Freigeben heißt sehen, nicht ändern.** Eine freigegebene Suche steht der
 * ganzen Organisation zur Verfügung; ändern und löschen darf sie nur, wer sie
 * angelegt hat ({@see SavedSearchPolicy}). Der Grund ist derselbe
 * wie beim Kommentar: eine Ansicht, die unter fremdem Namen etwas anderes zeigt
 * als gestern, ist schlimmer als keine — sie sieht vertraut aus.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $name
 * @property string $query
 * @property IssueSort $sort
 * @property bool $shared
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'organization_id',
    'user_id',
    'name',
    'query',
    'sort',
    'shared',
])]
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory;

    /**
     * Längster Name.
     *
     * Kurz genug, dass die Auswahlliste eine Liste bleibt und kein Fließtext:
     * was hier nicht hineinpasst, ist keine Bezeichnung mehr, sondern eine
     * Beschreibung — und die steht im Suchausdruck selbst.
     */
    public const NAME_LIMIT = 80;

    /**
     * Längster Suchausdruck — dieselbe Grenze wie im Suchfeld der Fehlerliste
     * ({@see IssueListRequest}). Sie hier großzügiger zu
     * fassen hieße, dass sich eine Suche speichern, aber nicht mehr anwenden
     * lässt.
     */
    public const QUERY_LIMIT = 500;

    /**
     * Wie viele Suchen ein Konto je Organisation anlegen darf.
     *
     * Eine Grenze, keine Beschränkung: die Auswahlliste wird bei jedem Aufruf
     * der Fehlerliste mitgeliefert, und was dort steht, soll man überblicken
     * können. Wer fünfzig Ansichten braucht, braucht in Wahrheit Ordner — und
     * die gibt es hier nicht.
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
     * Die Projekte, für die jemand diese Suche zu seinem Einstieg gemacht hat.
     *
     * @return HasMany<SavedSearchDefault, $this>
     */
    public function defaults(): HasMany
    {
        return $this->hasMany(SavedSearchDefault::class);
    }

    /**
     * Was ein Betrachter in dieser Organisation sehen darf: seine eigenen Suchen
     * und die freigegebenen der anderen.
     *
     * Als Scope und nicht als Prüfung je Zeile: die Auswahlliste ist eine
     * Abfrage, und „hinterher aussortieren" hieße, die fremden Suchen erst zu
     * laden und dann wegzuwerfen.
     *
     * @param  Builder<SavedSearch>  $query
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
            'sort' => IssueSort::class,
            'shared' => 'boolean',
        ];
    }
}
