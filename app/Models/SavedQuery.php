<?php

namespace App\Models;

use App\Policies\SavedQueryPolicy;
use App\Support\Dashboards\WidgetOverrides;
use App\Support\Dashboards\WidgetQuery;
use Database\Factories\SavedQueryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine gespeicherte Auswertung: eine freie Auswertung unter einem Namen.
 *
 * **Sie ist der festgehaltene Seitenzustand — mit Absicht.** Damit steht sie
 * genau andersherum als die gespeicherte Suche ({@see SavedSearch}), und die
 * Begründung dafür steht in der Migration: eine Suche sagt, *welche* Fehler
 * gemeint sind, eine Auswertung ist eine Frage *samt ihrem Ausschnitt*. „Fehler
 * nach Browser" über eine Stunde und über 90 Tage sind zwei Antworten, nicht
 * zwei Ansichten derselben. Beim Öffnen steht der gespeicherte Zeitraum deshalb
 * da — und lässt sich an der Leiste sofort umstellen wie jeder andere auch.
 *
 * **Die Frage ist dieselbe Beschreibung wie an einer Kachel.** {@see WidgetQuery}
 * beschreibt die sieben Angaben der Adresszeile; hier steht nichts anderes. Das
 * ist der Grund, warum sich eine gespeicherte Auswertung mit einem Klick als
 * Kachel übernehmen lässt: es ist keine Übersetzung, sondern dasselbe.
 *
 * **Freigeben heißt sehen, nicht ändern** — wie bei Suche und Dashboard. Wer
 * eine fremde Auswertung als Ausgangspunkt braucht, dupliziert sie
 * ({@see SavedQueryPolicy}).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $name
 * @property string $description
 * @property array<string, mixed> $query
 * @property array<string, mixed>|null $filters
 * @property bool $shared
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'organization_id',
    'user_id',
    'name',
    'description',
    'query',
    'filters',
    'shared',
])]
class SavedQuery extends Model
{
    /** @use HasFactory<SavedQueryFactory> */
    use HasFactory;

    /**
     * Längster Name.
     *
     * Dieselbe Grenze wie bei der gespeicherten Suche und beim Dashboard: was
     * hier nicht hineinpasst, ist keine Bezeichnung mehr, sondern eine
     * Beschreibung — und für die gibt es nebenan ein eigenes Feld.
     */
    public const NAME_LIMIT = 80;

    /** Längste Beschreibung — dieselbe Großzügigkeit wie beim Dashboard. */
    public const DESCRIPTION_LIMIT = 500;

    /**
     * Wie viele Auswertungen ein Konto je Organisation anlegen darf.
     *
     * Eine Grenze, keine Beschränkung: die Leiste wird bei jedem Aufruf der
     * Auswertungsseite mitgeliefert, und was dort steht, soll man überblicken
     * können.
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
     * Die gespeicherte Frage — nachsichtig gelesen, damit eine Auswertung ein
     * fortgefallenes Feld überlebt.
     */
    public function discoverQuery(): WidgetQuery
    {
        return WidgetQuery::fromArray($this->query);
    }

    /**
     * Der gespeicherte Ausschnitt: Zeitraum, Umgebung, Projekt.
     *
     * Dieselben fünf Angaben, die eine Kachel an der Filterleiste für sich
     * anders sehen kann — und mit denselben Regeln beim Lesen: ein Zeitraum
     * ohne Grenzen ist keiner, ein gelöschtes Projekt wird übergangen statt
     * abgewiesen. Eine zweite Fassung davon hier hieße, dieselbe Nachsicht ein
     * zweites Mal zu schreiben und beim nächsten Feld eine davon zu vergessen.
     */
    public function savedFilters(): WidgetOverrides
    {
        return WidgetOverrides::fromArray($this->filters);
    }

    /**
     * Was ein Betrachter in dieser Organisation sieht: seine eigenen
     * Auswertungen und die freigegebenen der anderen.
     *
     * Als Scope und nicht als Prüfung je Zeile: die Leiste ist eine Abfrage,
     * und „hinterher aussortieren" hieße, die fremden erst zu laden und dann
     * wegzuwerfen.
     *
     * @param  Builder<SavedQuery>  $query
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
            'query' => 'array',
            'filters' => 'array',
            'shared' => 'boolean',
        ];
    }
}
