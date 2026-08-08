<?php

namespace App\Models;

use App\Enums\EventLevel;
use App\Enums\IssueCategory;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\PerformanceProblem;
use App\Support\Tags\TagAggregates;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Der Fehler-Eintrag: ein Fehler, wie ihn jemand ansieht — mit Häufigkeit,
 * Betroffenen, erstem und letztem Auftreten.
 *
 * Die Gruppe (I5) sagt, welche Meldungen zusammengehören; der Eintrag sagt, was
 * das bedeutet. Er umfasst zunächst genau eine Gruppe und mehrere, sobald jemand
 * von Hand zusammenführt — deshalb zeigt die Gruppe auf ihn und nicht umgekehrt.
 *
 * **Zusammengeführt wird über `merged_into_id`, nicht über das Umhängen von
 * Gruppen.** Ein beigetretener Eintrag behält alles, was ihm gehört, und bekommt
 * nur einen Verweis auf den Kopf; das Auftrennen löscht diesen Verweis wieder.
 * Wer über einen Eintrag redet, meint deshalb je nach Frage zwei verschiedene
 * Mengen: seine **eigenen** Zeilen (`groups()`, `events()`, seine Zähler) und
 * die seiner **Mitglieder** ({@see memberIds()}). Alles, was jemandem angezeigt
 * wird, meint die zweite.
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
 * @property IssueCategory $category
 * @property int|null $merged_into_id
 * @property string|null $title
 * @property string|null $culprit
 * @property string|null $type
 * @property EventLevel $level
 * @property IssueStatus $status
 * @property IssuePriority $priority
 * @property int $times_seen
 * @property int $users_seen
 * @property int $time_lost_us
 * @property CarbonImmutable $first_seen
 * @property CarbonImmutable $last_seen
 * @property int|null $first_release_id
 * @property CarbonImmutable|null $first_release_at
 * @property int|null $last_release_id
 * @property CarbonImmutable|null $last_release_at
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by_id
 * @property int|null $resolved_in_release_id
 * @property bool $resolved_in_next_release
 * @property CarbonImmutable|null $ignored_at
 * @property int|null $ignored_by_id
 * @property int|null $ignore_count
 * @property int|null $ignore_window_minutes
 * @property int|null $ignore_users
 * @property int|null $ignore_times_seen
 * @property int|null $ignore_users_seen
 * @property CarbonImmutable|null $regressed_at
 * @property int|null $regressed_in_release_id
 * @property int|null $merged_sources_count nur nach `withCount('mergedSources')`
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
     *
     * **Titel, Fehlerstelle und Art stammen vom ersten Ereignis** und werden
     * nicht nachgeführt. Dieselbe Wahl wie bei der Begründung der Gruppe: sie
     * beschreiben, womit dieser Eintrag angefangen hat. Der Grad ist die
     * Ausnahme und folgt dem jüngsten Ereignis ({@see bump()}) — an ihm hängen
     * die Alarmregeln, und eine Verschärfung muss ankommen.
     */
    public static function forGroup(EventGroup $group, Event $event): self
    {
        if ($group->issue_id !== null) {
            $existing = self::query()->find($group->issue_id);

            if ($existing !== null) {
                // Der Kopf und nicht der Eintrag selbst: ist er von Hand einem
                // anderen beigetreten, gehören die folgenden Meldungen dorthin.
                // Sonst liefe die Zählung weiter am beigetretenen Eintrag
                // auf, den niemand mehr in der Liste sieht — der
                // zusammengeführte Fehler stünde still, obwohl er auftritt.
                //
                // Eine Abfrage mehr, aber nur für zusammengeführte Gruppen:
                // steht dort `null` — der Regelfall —, wird nichts nachgeladen.
                return $existing->head();
            }
        }

        $issue = self::query()->create([
            'project_id' => $group->project_id,
            'category' => IssueCategory::Error,
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
     * Fünf Dinge, jedes für sich sperrfrei:
     *
     *   1. Den Anspruch auf das Zählen sichern ({@see Event::claimForCounting()}).
     *   2. Häufigkeit, Zeitpunkte und Grad am Eintrag fortschreiben.
     *   3. Den Zeitreihen-Zähler des Fensters hochsetzen.
     *   4. Den Betroffenen zählen, falls er neu ist.
     *   5. Die Merkmale mitschreiben — Browser, Fassung, Server
     *      ({@see TagAggregates::record()}).
     *
     * Der Anspruch steht am Anfang und nicht am Ende: läuft dieselbe Meldung
     * ein zweites Mal durch die Kette — nach einem Fehlschlag, nach einer
     * Verbesserung an einem Schritt —, wird ihr ausgewerteter Datensatz ersetzt,
     * die Zähler dürfen sich aber nicht verdoppeln. Ein Zähler, der bei jedem
     * erneuten Anlauf steigt, ist schlimmer als gar keiner: er sieht richtig aus.
     *
     * **Bewusst ohne umschließende Transaktion.** Bricht die Verbindung
     * zwischen zwei der fünf Schritte ab, steht die Häufigkeit um eins höher als
     * die Zeitreihe — und der erneute Anlauf holt es nicht nach, denn der
     * Anspruch ist vergeben. Die Alternative wäre, alle fünf in eine Transaktion
     * zu legen: dann hielte jeder Arbeiter die Sperre auf der Zeile des
     * Eintrags bis zum Abschluss, und genau diese Zeile ist bei einem Ausfall
     * die, auf die alle gleichzeitig schreiben. Eine seltene Abweichung um eins
     * in einer Statistik ist der bessere Preis als ein Engpass im Regelbetrieb.
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

        // Die Merkmale hängen am selben Anspruch wie die übrigen Zähler und
        // stehen deshalb hier und nicht in einem eigenen Verarbeitungsschritt:
        // ein Schritt hinter diesem müsste den Anspruch ein zweites Mal
        // beurteilen, und zwei Stellen, die „wurde das schon gezählt?"
        // beantworten, sind eine zu viel.
        TagAggregates::record($this, $event);

        return true;
    }

    /**
     * Findet den Eintrag eines Leistungsproblems oder legt ihn an.
     *
     * Derselbe Ablauf wie bei einem Fehler, mit derselben Wettlaufsituation und
     * derselben Lösung ({@see forGroup()}) — die Gruppe entscheidet über die
     * bedingte Zuweisung, wer den Eintrag anlegen durfte. Was sich
     * unterscheidet, sind nur die Angaben, die hineingehen: die Überschrift
     * kommt vom Muster und seinem Gegenstand, die Fehlerstelle ist der Name des
     * Ablaufs, und der Grad ist eine Warnung, kein Fehler
     * ({@see PerformanceProblem::level()}).
     *
     * Auch hier gilt: die Angaben stammen vom **ersten** Fund und werden nicht
     * nachgeführt. Sie beschreiben, womit dieser Eintrag angefangen hat; was
     * sich seither geändert hat, steht in den Funden.
     */
    public static function forPerformance(EventGroup $group, PerformanceDetection $detection, string $title, ?string $culprit): self
    {
        if ($group->issue_id !== null) {
            $existing = self::query()->find($group->issue_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        $issue = self::query()->create([
            'project_id' => $group->project_id,
            'category' => IssueCategory::Performance,
            'title' => $title,
            'culprit' => $culprit,
            // Die Art ist das Muster selbst. Bei einem Fehler steht hier die
            // Klasse der Ausnahme — beides beantwortet dieselbe Frage: „was für
            // eine Sorte Problem ist das", und beides trägt denselben Filter.
            'type' => $detection->problem->value,
            'level' => $detection->problem->level(),
            'status' => IssueStatus::DEFAULT,
            'priority' => IssuePriority::DEFAULT,
            'first_seen' => $detection->occurred_at,
            'last_seen' => $detection->occurred_at,
        ]);

        $claimed = EventGroup::query()
            ->whereKey($group->id)
            ->whereNull('issue_id')
            ->update(['issue_id' => $issue->id]);

        if ($claimed === 1) {
            $group->issue_id = $issue->id;

            return $issue;
        }

        $issue->delete();

        $group->refresh();

        return self::query()->findOrFail($group->issue_id);
    }

    /**
     * Nimmt einen Fund in die Zähler auf.
     *
     * **Ohne eigenen Anspruch auf das Zählen**, anders als {@see record()}. Den
     * hat der Fund schon beim Anlegen erworben: sein eindeutiger Index über
     * (Ablauf, Fingerabdruck) lässt denselben Fund kein zweites Mal entstehen,
     * und diese Methode wird nur für einen **frisch angelegten** Fund gerufen
     * ({@see PerformanceDetection::claim()}). Ein zweiter Anspruch daneben wäre
     * eine zweite Stelle, die dieselbe Frage beantwortet.
     *
     * Der Betroffene kommt als fertiger Schlüssel herein und nicht als
     * Ereignis: eine Transaktion führt eine einzelne Kennung mit, kein
     * Nutzer-Feld mit Kennung, Name und Adresse zur Auswahl.
     */
    public function recordDetection(PerformanceDetection $detection, ?string $userKey): void
    {
        $occurred = CarbonImmutable::parse($detection->occurred_at)->utc();

        $this->bumpPerformance($occurred, $detection->time_lost_us);

        IssueCount::record($this, $occurred);

        IssueUser::note($this, $userKey, $occurred);
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
     * Dasselbe für einen Fund — mit der verlorenen Zeit statt des Grades.
     *
     * Die verlorene Zeit wird **addiert** und nicht ersetzt: gefragt ist, was
     * dieses Problem insgesamt kostet, nicht was es beim letzten Mal gekostet
     * hat. Erst diese Summe macht die Liste sortierbar nach dem, was sich zu
     * beheben lohnt — ein Muster, das in jedem zweiten Aufruf zehn Millisekunden
     * frisst, steht damit vor dem einmaligen Ausreißer von einer Sekunde.
     *
     * Der Grad bleibt, wie er ist. Er hängt am Muster und nicht am einzelnen
     * Fund; ein besonders schlimmer Fall macht aus einer langsamen Abfrage
     * keinen Fehler.
     */
    private function bumpPerformance(CarbonImmutable $occurred, int $timeLostUs): void
    {
        $at = $occurred->format('Y-m-d H:i:s');
        $now = Carbon::now()->format('Y-m-d H:i:s');

        DB::update(
            'update '.$this->getTable().' set '
            .'times_seen = times_seen + 1, '
            .'time_lost_us = time_lost_us + ?, '
            .'first_seen = case when first_seen > ? then ? else first_seen end, '
            .'last_seen = case when last_seen < ? then ? else last_seen end, '
            .'updated_at = ? '
            .'where id = ?',
            [max(0, $timeLostUs), $at, $at, $at, $at, $now, $this->id],
        );
    }

    /**
     * Vermerkt, in welcher Version dieser Fehler gesehen wurde.
     *
     * Zwei Angaben in einer Anweisung: die **erste** Version, in der er auftrat,
     * und die **letzte**. Sie stehen am Eintrag und nicht in einer Abfrage über
     * die Ereignisse, weil letztere ein `min`/`max` über die größte Tabelle
     * dieser Anwendung wäre — bei jedem Aufschlagen einer Fehlerseite.
     *
     * Entschieden wird über die eigenen Zeitstempel (`first_release_at`,
     * `last_release_at`) und nicht über `first_seen`/`last_seen`. Der
     * Unterschied ist nicht Genauigkeit, sondern Bedeutung: die beiden stehen
     * für **alle** Meldungen, diese hier nur für die mit Versionsangabe. Ein
     * SDK, das die Version erst seit gestern mitschickt, hat einen Eintrag, der
     * seit Wochen läuft und dessen erste bekannte Version von gestern ist —
     * gegen `first_seen` verglichen käme dagegen nie eine erste Version zustande.
     *
     * Sperrfrei und mit `case when` wie {@see bump()} und aus denselben zwei
     * Gründen: bei einem Ausfall schreiben alle Arbeiter auf dieselbe Zeile,
     * und Meldungen kommen nicht in ihrer zeitlichen Reihenfolge an. Eine
     * nachgereichte alte Meldung darf die zuletzt betroffene Version nicht
     * zurückdrehen — wohl aber die zuerst betroffene, denn genau dafür ist sie
     * da.
     *
     * Die beiden Vergleiche sind bewusst nicht spiegelbildlich: die letzte
     * Version wird bei Gleichstand (`<=`) ersetzt, die erste nicht (`>`).
     * Treffen zwei Meldungen mit demselben Zeitstempel aus verschiedenen
     * Versionen ein, bleibt damit die zuerst gesehene die erste, und die zuletzt
     * verarbeitete wird die letzte — beides die Antwort, die man erwartet. Eine
     * Rangfolge nach Versionsnummer wäre hier falsch: welche Auslieferung zuerst
     * lief, sagt die Uhr und nicht die Nummer.
     */
    public function linkRelease(Release $release, CarbonImmutable $occurred): void
    {
        $at = $occurred->utc()->format('Y-m-d H:i:s');
        $now = Carbon::now()->format('Y-m-d H:i:s');

        DB::update(
            'update '.$this->getTable().' set '
            .'first_release_id = case when first_release_at is null or first_release_at > ? then ? else first_release_id end, '
            .'first_release_at = case when first_release_at is null or first_release_at > ? then ? else first_release_at end, '
            .'last_release_id = case when last_release_at is null or last_release_at <= ? then ? else last_release_id end, '
            .'last_release_at = case when last_release_at is null or last_release_at <= ? then ? else last_release_at end, '
            .'updated_at = ? '
            .'where id = ?',
            [$at, $release->id, $at, $at, $at, $release->id, $at, $at, $now, $this->id],
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
     * Die **eigenen** Gruppen dieses Eintrags.
     *
     * Ohne die seiner Mitglieder: die gehören denen und kommen erst über
     * {@see groupIds()} dazu. Wer alle Meldungen eines zusammengeführten
     * Eintrags meint — und das ist beim Anzeigen fast immer der Fall —, nimmt
     * deshalb nicht diese Beziehung.
     *
     * @return HasMany<EventGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(EventGroup::class);
    }

    /**
     * Die Ereignisse dieses Eintrags — über seine eigenen Gruppen.
     *
     * Dieselbe Einschränkung wie bei {@see groups()}: die Meldungen der
     * Mitglieder sind nicht dabei.
     *
     * @return HasManyThrough<Event, EventGroup, $this>
     */
    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, EventGroup::class, 'issue_id', 'event_group_id');
    }

    /**
     * Der Eintrag, dem dieser beigetreten ist — oder `null`, wenn er für sich
     * steht.
     *
     * @return BelongsTo<self, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * Die Einträge, die diesem beigetreten sind — die Untergruppen, die die
     * Detailseite zeigt.
     *
     * @return HasMany<self, $this>
     */
    public function mergedSources(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /**
     * Ist dieser Eintrag einem anderen beigetreten?
     */
    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /**
     * Der Eintrag, unter dem dieser angezeigt und gezählt wird.
     *
     * **Genau eine Stufe, keine Kette.** Ein Kopf darf nicht selbst beitreten
     * und ein Beigetretener nicht Kopf sein ({@see App\Support\Issues\IssueMerging});
     * damit ist die Auflösung ein einzelner Schritt und nicht eine Schleife, die
     * im Fehlerfall keinen Boden hat. Der Preis ist eine Regel mehr beim
     * Zusammenführen, der Gewinn ist, dass jede Leseabfrage mit einem `whereIn`
     * auskommt.
     */
    public function head(): self
    {
        if (! $this->isMerged()) {
            return $this;
        }

        // `?? $this`, falls der Kopf inzwischen gelöscht wurde: der
        // Fremdschlüssel setzt die Spalte dann auf `null`, aber diese Instanz
        // kann älter sein als das Löschen.
        return $this->mergedInto ?? $this;
    }

    /**
     * Die Kennungen dieses Eintrags und aller ihm beigetretenen.
     *
     * Das ist die Menge, über die summiert wird: Verlaufsgrafik, Merkmale und
     * das Blättern zwischen den Meldungen meinen den Fehler, wie er dasteht —
     * und dazu gehören die Untergruppen.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        if ($this->isMerged()) {
            // Ein beigetretener Eintrag hat keine eigenen Mitglieder (eine
            // Stufe, siehe {@see head()}). Die Abfrage wäre garantiert leer —
            // hier wird sie erst gar nicht gestellt.
            return [$this->id];
        }

        // Die schon geladene Beziehung, falls es sie gibt: die Detailseite lädt
        // die Untergruppen ohnehin, und die Merkmale fragen zweimal danach —
        // ohne das wären es zwei Abfragen für eine Antwort, die schon dasteht.
        $members = $this->relationLoaded('mergedSources')
            ? $this->mergedSources
            : $this->mergedSources()->get(['id']);

        return [
            $this->id,
            ...$members->pluck('id')->all(),
        ];
    }

    /**
     * Die Gruppen dieses Eintrags **samt** denen seiner Mitglieder, als
     * Unterabfrage.
     *
     * Eine Unterabfrage und keine Liste von Kennungen: ein Eintrag mit vielen
     * Untergruppen brächte sonst deren Kennungen einzeln in jede Abfrage, und
     * das ist genau die Stelle, an der eine Anfrage aus der Adresszeile beliebig
     * lang wird.
     *
     * @return Builder<EventGroup>
     */
    public function groupIds(): Builder
    {
        return EventGroup::query()
            ->whereIn('issue_id', $this->memberIds())
            ->select('event_groups.id');
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
     * Die Funde dieses Eintrags — bei einem Fehler immer leer.
     *
     * Das Gegenstück zu {@see events()}: dort das einzelne Auftreten eines
     * Fehlers, hier das einzelne Auftreten eines Musters.
     *
     * @return HasMany<PerformanceDetection, $this>
     */
    public function detections(): HasMany
    {
        return $this->hasMany(PerformanceDetection::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Die Version, in der dieser Fehler zum ersten Mal auftrat.
     *
     * `null`, solange keine Meldung eine Version mitgebracht hat — und das ist
     * kein Sonderfall, sondern der Normalzustand bei einem SDK ohne
     * `release`-Angabe.
     *
     * @return BelongsTo<Release, $this>
     */
    public function firstRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'first_release_id');
    }

    /**
     * Die Version, in der dieser Fehler zuletzt auftrat.
     *
     * @return BelongsTo<Release, $this>
     */
    public function lastRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'last_release_id');
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
     * Nur Einträge, die für sich stehen — ohne die einem anderen beigetretenen.
     *
     * Der Filter der Fehlerliste. Ein beigetretener Eintrag ist nicht
     * verschwunden, er steht nur nicht mehr für sich: seine Zahlen sind im Kopf
     * enthalten, und daneben ein zweites Mal einzeln aufzutauchen wäre genau die
     * Doppelzählung, gegen die jemand den Fehler zusammengeführt hat.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStandalone(Builder $query): void
    {
        $query->whereNull('merged_into_id');
    }

    /**
     * Wer den Eintrag erledigt hat — `null`, solange er offen ist oder das
     * Konto gelöscht wurde. Wer es war, steht dann noch im Verlauf, wo der Name
     * mitgeschrieben wird.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Die Version, in der der Eintrag als behoben gilt.
     *
     * Nicht dasselbe wie {@see lastRelease()}: die eine sagt, wo er zuletzt
     * auftrat, die andere, wo er behoben sein soll. Beim Erledigen „in dieser
     * Version" fallen sie zusammen — danach nicht mehr, und genau daran erkennt
     * die Rückkehr-Erkennung (S8) einen Rückfall.
     *
     * @return BelongsTo<Release, $this>
     */
    public function resolvedInRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'resolved_in_release_id');
    }

    /**
     * Die Version, in der der Eintrag zurückgekommen ist (S8).
     *
     * `null`, solange es keinen Rückfall gab — und auch dann, wenn es einen gab,
     * die Meldung aber keine Versionsangabe trug. Ein Rückfall ohne Version ist
     * einer; nur nennen kann ihn der Verlauf dann nicht.
     *
     * @return BelongsTo<Release, $this>
     */
    public function regressedInRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'regressed_in_release_id');
    }

    /**
     * Ist dieser Eintrag von selbst wieder aufgegangen?
     *
     * Die Marke und nicht der Zustand: „wieder aufgetreten" ist eine Herkunft,
     * kein vierter Zustand — der Eintrag ist offen wie jeder andere offene auch
     * (siehe die Migration).
     */
    public function hasRegressed(): bool
    {
        return $this->regressed_at !== null;
    }

    /**
     * Nur die Einträge, die zurückgekommen sind — `is:regressed`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRegressed(Builder $query): void
    {
        $query->whereNotNull('regressed_at');
    }

    /**
     * Wer den Eintrag stummgeschaltet hat.
     *
     * @return BelongsTo<User, $this>
     */
    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by_id');
    }

    /**
     * Der Aktivitätsverlauf dieses Eintrags: wer wann was getan hat.
     *
     * @return HasMany<IssueActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(IssueActivity::class);
    }

    /**
     * Wer sich diesen Eintrag gemerkt hat.
     *
     * @return BelongsToMany<User, $this>
     */
    public function bookmarkedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_bookmarks')->withTimestamps();
    }

    /**
     * Wer diesen Eintrag abonniert hat.
     *
     * Ausdrücklich abonniert, nicht „bekommt Benachrichtigungen": womit und ob
     * überhaupt zugestellt wird, entscheiden die Benachrichtigungs-
     * Einstellungen (A5). Hier steht nur der Wunsch, von **diesem** Fehler zu
     * hören.
     *
     * @return BelongsToMany<User, $this>
     */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_subscriptions')->withTimestamps();
    }

    /**
     * Wer sich um diesen Fehler erkennbar gekümmert hat — für die
     * Benachrichtigung beim Ablauf einer Stummschaltung und für die Anzeige.
     *
     * @return array<int, int>
     */
    public function watcherIds(): array
    {
        return $this->subscribers()->pluck('users.id')->all();
    }

    /**
     * Nur Einträge einer Kategorie.
     *
     * **Jede** Liste setzt diesen Filter, und zwar ausdrücklich: eine Ansicht,
     * die ihn vergisst, zeigt langsame Abfragen zwischen Ausnahmen — und das
     * fällt nicht auf, solange noch kein Leistungsproblem erkannt wurde.
     * Deshalb gibt es keinen Vorgabewert, der stillschweigend einspringt.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfCategory(Builder $query, IssueCategory $category): void
    {
        $query->where('category', $category);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'category',
        'title',
        'culprit',
        'type',
        'level',
        'status',
        'priority',
        'times_seen',
        'users_seen',
        'time_lost_us',
        'first_seen',
        'last_seen',
        'first_release_id',
        'first_release_at',
        'last_release_id',
        'last_release_at',
        'resolved_at',
        'resolved_by_id',
        'resolved_in_release_id',
        'resolved_in_next_release',
        'ignored_at',
        'ignored_by_id',
        'ignore_count',
        'ignore_window_minutes',
        'ignore_users',
        'ignore_times_seen',
        'ignore_users_seen',
        'regressed_at',
        'regressed_in_release_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => IssueCategory::class,
            'level' => EventLevel::class,
            'status' => IssueStatus::class,
            'priority' => IssuePriority::class,
            'times_seen' => 'integer',
            'users_seen' => 'integer',
            'time_lost_us' => 'integer',
            // `immutable_datetime`, damit ein versehentliches `->addHour()` auf
            // einer geteilten Instanz nicht den Eintrag selbst verschiebt.
            'first_seen' => 'immutable_datetime',
            'last_seen' => 'immutable_datetime',
            'first_release_at' => 'immutable_datetime',
            'last_release_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'resolved_in_next_release' => 'boolean',
            'ignored_at' => 'immutable_datetime',
            'ignore_count' => 'integer',
            'ignore_window_minutes' => 'integer',
            'ignore_users' => 'integer',
            'ignore_times_seen' => 'integer',
            'ignore_users_seen' => 'integer',
            'regressed_at' => 'immutable_datetime',
        ];
    }
}
