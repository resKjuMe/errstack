<?php

namespace App\Models;

use App\Enums\EventLevel;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Der Fehler-Eintrag: ein Fehler, wie ihn jemand ansieht — mit Häufigkeit,
 * Betroffenen, erstem und letztem Auftreten.
 *
 * Die Gruppe (I5) sagt, welche Meldungen zusammengehören; der Eintrag sagt, was
 * das bedeutet. Er umfasst zunächst genau eine Gruppe und ab S9 mehrere, wenn
 * jemand von Hand zusammenführt — deshalb zeigt die Gruppe auf ihn und nicht
 * umgekehrt.
 *
 * **Das Zählen ist der Kern dieser Klasse, und es ist sperrfrei.** Kein
 * `lockForUpdate`, kein Lesen-Ändern-Schreiben: jede Fortschreibung ist eine
 * einzelne SQL-Anweisung, in der die Datenbank den alten Wert selbst einsetzt.
 * Der Unterschied ist nicht Geschmack, sondern der zwischen „hält 1.000
 * Ereignisse je Minute aus" und „alle Arbeiter warten auf dieselbe Zeile":
 * derselbe Fehler ist bei einem Ausfall in **jeder** gleichzeitig verarbeiteten
 * Meldung derselbe, und damit wäre genau die eine heiße Zeile der Engpass, an
 * dem die Aufnahme stehen bleibt.
 *
 * Die Kehrseite: nach dem Zählen ist die Instanz im Speicher **veraltet**. Wer
 * die neuen Werte braucht, holt sie mit `fresh()`. Das ist der bewusste Preis —
 * die Alternative wäre eine Leseabfrage je Ereignis, und das ist genau die
 * Last, die hier vermieden wird.
 *
 * @property int $id
 * @property int $project_id
 * @property string|null $title
 * @property string|null $culprit
 * @property string|null $type
 * @property EventLevel $level
 * @property IssueStatus $status
 * @property IssuePriority $priority
 * @property int $times_seen
 * @property int $users_seen
 * @property CarbonImmutable $first_seen
 * @property CarbonImmutable $last_seen
 */
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    /**
     * Findet den Eintrag einer Gruppe oder legt ihn an.
     *
     * Der Ablauf ist der einer Wettlaufsituation und nicht der eines
     * Nachschlags: bei einem gerade zum ersten Mal auftretenden Fehler laufen
     * mehrere Arbeiter gleichzeitig auf dieselbe frische Gruppe. Ein `exists()`
     * vor dem Anlegen wäre nur eine Momentaufnahme — beide bekämen „nein", und
     * derselbe Fehler stünde zweimal in der Liste, jeder mit der Hälfte der
     * Zähler.
     *
     * Entscheiden muss deshalb die Datenbank. Der eindeutige Index, den die
     * Gruppe dafür hätte, fehlt hier absichtlich: ab S9 zeigen mehrere Gruppen
     * auf denselben Eintrag, und ein `unique` auf `issue_id` würde genau das
     * verbieten. Die Entscheidung fällt stattdessen über die **bedingte
     * Zuweisung**: der Eintrag wird angelegt und dann nur dann an die Gruppe
     * geschrieben, wenn dort noch keiner steht. Wer dabei leer ausgeht, räumt
     * seinen Eintrag wieder weg und nimmt den des Gewinners — er hat noch keine
     * Zähler, es geht nichts verloren.
     */
    public static function forGroup(EventGroup $group, Event $event): self
    {
        if ($group->issue_id !== null) {
            $existing = self::query()->find($group->issue_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        $issue = self::query()->create([
            'project_id' => $group->project_id,
            'title' => $event->title,
            'culprit' => $event->culprit,
            'type' => self::typeOf($event),
            'level' => $event->level,
            'status' => IssueStatus::DEFAULT,
            'priority' => IssuePriority::DEFAULT,
            // Beide Zeitpunkte vom auslösenden Ereignis, die Zähler bei null:
            // gezählt wird erst in {@see record()}, und zwar auch dieses
            // Ereignis. Der Eintrag anzulegen und das Zählen sind zwei
            // Schritte, damit es nur **einen** Weg gibt, auf dem ein Zähler
            // steigt.
            'first_seen' => $event->occurred_at,
            'last_seen' => $event->occurred_at,
        ]);

        $claimed = EventGroup::query()
            ->whereKey($group->id)
            ->whereNull('issue_id')
            ->update(['issue_id' => $issue->id]);

        if ($claimed === 1) {
            $group->issue_id = $issue->id;

            return $issue;
        }

        // Ein anderer Arbeiter war schneller. Sein Eintrag ist unser Eintrag.
        $issue->delete();

        $group->refresh();

        return self::query()->findOrFail($group->issue_id);
    }

    /**
     * Nimmt ein Ereignis in die Zähler auf.
     *
     * Vier Dinge, jedes für sich sperrfrei:
     *
     *   1. Den Anspruch auf das Zählen sichern ({@see Event::claimForCounting()}).
     *   2. Häufigkeit, Zeitpunkte und Grad am Eintrag fortschreiben.
     *   3. Den Zeitreihen-Zähler des Fensters hochsetzen.
     *   4. Den Betroffenen zählen, falls er neu ist.
     *
     * Der Anspruch steht am Anfang und nicht am Ende: läuft dieselbe Meldung
     * ein zweites Mal durch die Kette — nach einem Fehlschlag, nach einer
     * Verbesserung an einem Schritt —, wird ihr ausgewerteter Datensatz ersetzt,
     * die Zähler dürfen sich aber nicht verdoppeln. Ein Zähler, der bei jedem
     * erneuten Anlauf steigt, ist schlimmer als gar keiner: er sieht richtig aus.
     *
     * @return bool `false`, wenn dieses Ereignis bereits gezählt war.
     */
    public function record(Event $event): bool
    {
        if (! $event->claimForCounting()) {
            return false;
        }

        $occurred = CarbonImmutable::parse($event->occurred_at)->utc();

        $this->bump($occurred, $event->level);

        IssueCount::record($this, $occurred);

        IssueUser::record($this, $event);

        return true;
    }

    /**
     * Schreibt Häufigkeit, Zeitpunkte und Grad in einer Anweisung fort.
     *
     * `times_seen = times_seen + 1` statt Lesen-Ändern-Schreiben: die Datenbank
     * setzt den alten Wert selbst ein, und zwei gleichzeitige Anweisungen
     * ergeben zwei. Gelesen und im Speicher erhöht ergäben sie eins — das ist
     * das „verlorene Hochzählen", und bei einer Fehlerflut ist es nicht die
     * Ausnahme, sondern der Regelfall.
     *
     * Erstes und letztes Auftreten mit `case when`, aus demselben Grund und mit
     * einem zweiten dazu: Meldungen kommen **nicht** in ihrer zeitlichen
     * Reihenfolge an. Ein SDK, das nach einer Netztrennung seine Warteschlange
     * leert, liefert Stunden später Ereignisse von vorhin — `last_seen = ?`
     * würde den Eintrag dann in die Vergangenheit zurückdatieren.
     *
     * Der Grad folgt dem **jüngsten** Ereignis und nicht dem ersten: er ist die
     * Angabe, an der Alarmregeln hängen, und ein Fehler, der von `warning` auf
     * `fatal` gewechselt ist, ist ab dann ein anderer Fall. Verglichen wird
     * dafür wieder gegen `last_seen` — ein nachgereichtes altes Ereignis darf
     * den Grad nicht zurückdrehen.
     */
    private function bump(CarbonImmutable $occurred, EventLevel $level): void
    {
        $at = $occurred->format('Y-m-d H:i:s');
        $now = Carbon::now()->format('Y-m-d H:i:s');

        DB::update(
            'update '.$this->getTable().' set '
            .'times_seen = times_seen + 1, '
            .'first_seen = case when first_seen > ? then ? else first_seen end, '
            .'level = case when last_seen <= ? then ? else level end, '
            .'last_seen = case when last_seen < ? then ? else last_seen end, '
            .'updated_at = ? '
            .'where id = ?',
            [$at, $at, $at, $level->value, $at, $at, $now, $this->id],
        );
    }

    /**
     * Die Art des Fehlers: bei einer Ausnahme deren Klasse.
     *
     * Die **letzte** Ausnahme der Ursachenkette, nicht die erste: die Kette ist
     * von der ältesten Ursache an geordnet, und gesehen hat die Anwendung die
     * letzte. Eine bloße Nachricht hat keine Art — dort bleibt das Feld leer,
     * statt „message" hineinzuschreiben und damit einen Filterwert zu erfinden.
     */
    private static function typeOf(Event $event): ?string
    {
        $exceptions = $event->exceptions ?? [];

        if ($exceptions === []) {
            return null;
        }

        $type = $exceptions[array_key_last($exceptions)]['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Die Gruppen, aus denen dieser Eintrag besteht.
     *
     * Bis S9 genau eine. Die Beziehung steht trotzdem in der Mehrzahl, weil
     * sich daran später nichts ändern soll — nur die Zahl der Zeilen.
     *
     * @return HasMany<EventGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(EventGroup::class);
    }

    /**
     * Die Ereignisse dieses Eintrags — über seine Gruppen.
     *
     * @return HasMany<Event, EventGroup>
     */
    public function events(): HasMany
    {
        /** @var HasMany<Event, EventGroup> */
        return $this->hasManyThrough(Event::class, EventGroup::class, 'issue_id', 'event_group_id');
    }

    /**
     * Die Zeitreihe dieses Eintrags.
     *
     * @return HasMany<IssueCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(IssueCount::class);
    }

    /**
     * @return HasMany<IssueUser, $this>
     */
    public function affectedUsers(): HasMany
    {
        return $this->hasMany(IssueUser::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Die Fehlerliste, wie sie aufgeschlagen wird: zuletzt Aufgetretenes zuerst.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('last_seen')->orderByDesc('id');
    }

    /**
     * Nur die offenen Einträge.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', IssueStatus::Unresolved);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'title',
        'culprit',
        'type',
        'level',
        'status',
        'priority',
        'times_seen',
        'users_seen',
        'first_seen',
        'last_seen',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => EventLevel::class,
            'status' => IssueStatus::class,
            'priority' => IssuePriority::class,
            'times_seen' => 'integer',
            'users_seen' => 'integer',
            // `immutable_datetime`, damit ein versehentliches `->addHour()` auf
            // einer geteilten Instanz nicht den Eintrag selbst verschiebt.
            'first_seen' => 'immutable_datetime',
            'last_seen' => 'immutable_datetime',
        ];
    }
}
