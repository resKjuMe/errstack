<?php

namespace App\Support\Issues;

use App\Enums\IgnoreOutcome;
use App\Enums\IssueActivityType;
use App\Enums\IssueIgnoreMode;
use App\Enums\IssueResolveMode;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueDiscard;
use App\Models\Release;
use App\Models\User;
use App\Support\Ingest\Processing\Steps\AggregateIssue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Die Zustandsaktionen an Fehler-Einträgen: erledigen, wieder öffnen,
 * stummschalten, merken, abonnieren, löschen.
 *
 * **Jede Aktion nimmt eine Abfrage entgegen, nicht einen Eintrag.** Das ist die
 * eine Entscheidung, aus der sich der Rest dieser Klasse ergibt: „diesen einen"
 * ist `whereKey($id)`, „diese drei" ist `whereIn`, und „alle 12.480 der
 * Auswahl" ist genau die Abfrage, mit der die Liste gebaut wurde. Mit einer
 * zweiten Fassung je Sammelaktion gäbe es zwei Stellen, an denen dieselbe Regel
 * steht — und erfahrungsgemäß eine davon ohne den Verlaufseintrag.
 *
 * **Fortgeschrieben wird in Blöcken, nicht Zeile für Zeile.** Je Block eine
 * `update`-Anweisung und **ein** Einfügen der Verlaufseinträge. Bei 12.480
 * Einträgen ist der Unterschied nicht Feinschliff, sondern der zwischen einer
 * Abfrage je Block und 24.960 einzelnen — mit einem Zeitlimit dazwischen.
 *
 * **Die Zähler bleiben unberührt.** Weder das Erledigen noch das Stummschalten
 * rührt `times_seen`, `users_seen` oder die Zeitreihe an: ein erledigter Fehler,
 * der weiter auftritt, wird weiter gezählt. Wer das nicht will, löscht.
 */
final class IssueActions
{
    /**
     * Bis zu wie vielen Einträgen ein Rückweg angeboten wird.
     *
     * Der Rückweg braucht die Kennungen der betroffenen Zeilen — sie stehen in
     * der Sitzung und wandern von dort in die Oberfläche. Bei einer Aktion über
     * 100.000 Treffer wären das 100.000 Zahlen in einem Cookie-großen Behälter,
     * und die Meldung „rückgängig" wäre eine Zusage, die beim Klicken zerbricht.
     *
     * Oberhalb der Grenze bleibt die Aktion trotzdem umkehrbar — nur eben über
     * die Liste („erledigt" auswählen, „wieder öffnen"), und die Meldung sagt
     * das dann auch.
     */
    public const UNDO_LIMIT = 200;

    /**
     * Wie viele Einträge auf einmal verarbeitet werden.
     */
    private const CHUNK = 500;

    public function __construct(
        private readonly ?User $actor = null,
    ) {}

    /**
     * Erledigt — sofort, in der Version des letzten Auftretens oder mit der
     * nächsten Auslieferung.
     *
     * „In dieser Version" bezieht sich auf `last_release_id` und damit auf die
     * Version, in der der Fehler zuletzt gesehen wurde — nicht auf die neueste
     * des Projekts. Der Unterschied wird sichtbar, sobald zwei Auslieferungen
     * dicht aufeinander folgen: gemeint ist „behoben in dem Stand, aus dem die
     * Meldungen kamen", und das ist der Stand des Fehlers, nicht der des
     * Projekts. Als Spalten-auf-Spalte-Zuweisung ist das zugleich derselbe eine
     * `update` wie in den übrigen Fällen.
     *
     * **„Mit der nächsten Auslieferung" merkt sich dieselbe Version** und
     * unterscheidet sich nur im Schalter daneben. Das ist keine Sparsamkeit,
     * sondern die Bedeutung: „behoben, sobald das nächste Mal ausgeliefert wird"
     * ist eine Aussage über alles, was **nach** dem jetzigen Stand kommt — und
     * der jetzige Stand ist der, aus dem die Meldungen kamen. Ohne diesen
     * Bezugspunkt wäre die Rückfallerkennung (S8) bei dieser Art zu erledigen
     * blind: sie müsste entweder jede spätere Meldung als Rückfall werten
     * (dann wäre „nächste Auslieferung" dasselbe wie „sofort") oder gar keine.
     *
     * @param  Builder<Issue>  $query
     */
    public function resolve(Builder $query, IssueResolveMode $mode): IssueActionResult
    {
        $now = CarbonImmutable::now();

        return $this->apply(
            $query,
            fn (array $ids): int => Issue::query()->whereIn('id', $ids)->update([
                'status' => IssueStatus::Resolved,
                'resolved_at' => $now,
                'resolved_by_id' => $this->actor?->id,
                'resolved_in_release_id' => $mode === IssueResolveMode::Now
                    ? null
                    : DB::raw('last_release_id'),
                'resolved_in_next_release' => $mode === IssueResolveMode::NextRelease,
                // Ein Rückfall ist mit dem Erledigen abgehandelt: der Eintrag
                // war zurück, jemand hat sich gekümmert. Die Marke stehen zu
                // lassen hieße, ihn dauerhaft in der Ansicht „wieder
                // aufgetreten" zu führen.
                ...self::clearedRegression(),
                // Eine Stummschaltung endet mit dem Erledigen. Sie stehen zu
                // lassen wäre die stillere und die schlechtere Wahl: käme der
                // Fehler zurück und würde wieder geöffnet, gälte plötzlich eine
                // Bedingung, die niemand mehr auf dem Schirm hat.
                ...self::clearedIgnore(),
                'updated_at' => $now,
            ]),
            IssueActivityType::Resolved,
            ['mode' => $mode->value],
        );
    }

    /**
     * Wieder öffnen — der Rückweg aus „erledigt" und aus „stummgeschaltet".
     *
     * Ein Weg für beide, weil es aus beiden Zuständen dasselbe Ziel ist: der
     * Eintrag steht wieder in der Liste. Welcher Vermerk in den Verlauf kommt,
     * hängt trotzdem am Ausgangszustand — „wieder geöffnet" nach dem Erledigen
     * und nach dem Stummschalten sind für den, der den Verlauf liest, zwei
     * verschiedene Vorgänge.
     *
     * @param  Builder<Issue>  $query
     */
    public function unresolve(Builder $query): IssueActionResult
    {
        $now = CarbonImmutable::now();

        return $this->apply(
            $query,
            fn (array $ids): int => Issue::query()->whereIn('id', $ids)->update([
                'status' => IssueStatus::Unresolved,
                'resolved_at' => null,
                'resolved_by_id' => null,
                'resolved_in_release_id' => null,
                'resolved_in_next_release' => false,
                ...self::clearedIgnore(),
                // Von Hand geöffnet ist kein Rückfall: die Marke sagt „das ist
                // von selbst zurückgekommen", und wer sie hier stehen ließe,
                // machte aus einer Entscheidung eine Beobachtung.
                ...self::clearedRegression(),
                'updated_at' => $now,
            ]),
            IssueActivityType::Unresolved,
        );
    }

    /**
     * Stummschalten — dauerhaft oder unter einer Bedingung.
     *
     * Die Zählerstände werden beim Stummschalten mitgeschrieben
     * (`ignore_times_seen`, `ignore_users_seen`). Sie sind der Bezugspunkt der
     * Bedingung: „hundert weitere" heißt hundert **ab jetzt**, und ohne den
     * Ausgangsstand wäre ein Eintrag mit 40.000 Auftreten in derselben Sekunde
     * wieder wach, in der ihn jemand stummgeschaltet hat. Deshalb die Zuweisung
     * von Spalte auf Spalte statt eines festen Werts — sie gilt je Zeile.
     *
     * @param  Builder<Issue>  $query
     */
    public function ignore(Builder $query, IssueIgnoreMode $mode, ?int $count = null, ?int $window = null): IssueActionResult
    {
        $now = CarbonImmutable::now();
        $condition = IgnoreCondition::columnsFor($mode, $count, $window);

        return $this->apply(
            $query,
            fn (array $ids): int => Issue::query()->whereIn('id', $ids)->update([
                'status' => IssueStatus::Ignored,
                'ignored_at' => $now,
                'ignored_by_id' => $this->actor?->id,
                'ignore_count' => $condition['count'],
                'ignore_window_minutes' => $condition['window'],
                'ignore_users' => $condition['users'],
                'ignore_times_seen' => DB::raw('times_seen'),
                'ignore_users_seen' => DB::raw('users_seen'),
                'resolved_at' => null,
                'resolved_by_id' => null,
                'resolved_in_release_id' => null,
                'resolved_in_next_release' => false,
                ...self::clearedRegression(),
                'updated_at' => $now,
            ]),
            IssueActivityType::Ignored,
            array_filter([
                'mode' => $mode->value,
                'count' => $condition['count'],
                'window' => $condition['window'],
                'users' => $condition['users'],
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Merken und Vormerkung aufheben.
     *
     * @param  Builder<Issue>  $query
     */
    public function bookmark(Builder $query, bool $on): IssueActionResult
    {
        return $this->attach($query, 'issue_bookmarks', $on,
            $on ? IssueActivityType::Bookmarked : IssueActivityType::Unbookmarked);
    }

    /**
     * Abonnieren und abbestellen.
     *
     * @param  Builder<Issue>  $query
     */
    public function subscribe(Builder $query, bool $on): IssueActionResult
    {
        return $this->attach($query, 'issue_subscriptions', $on,
            $on ? IssueActivityType::Subscribed : IssueActivityType::Unsubscribed);
    }

    /**
     * Löschen — wahlweise samt künftigem Verwerfen.
     *
     * **Die Gruppen und ihre Meldungen bleiben.** Der Eintrag ist die Aussage
     * „will ich nicht mehr sehen", nicht „das ist nie passiert" — dieselbe
     * Begründung wie am Fremdschlüssel `event_groups.issue_id`, der beim Löschen
     * nur geleert wird. Ohne die Verwerfung legt die nächste Meldung deshalb
     * einen neuen Eintrag an; das ist gewollt und der Unterschied zwischen den
     * beiden Spielarten.
     *
     * Der Verlaufseintrag wird **vor** dem Löschen geschrieben und überlebt es
     * ohne Eintrag ({@see IssueActivityType::outlivesIssue()}) — er ist der
     * Beleg dafür, warum seither nichts mehr ankommt.
     *
     * @param  Builder<Issue>  $query
     */
    public function delete(Builder $query, bool $discard): IssueActionResult
    {
        $type = $discard ? IssueActivityType::Discarded : IssueActivityType::Deleted;
        $count = 0;
        $fingerprints = [];

        self::rows($query, ['issues.title', 'issues.culprit'])
            ->chunkById(self::CHUNK, function (Collection $issues) use (&$count, &$fingerprints, $discard, $type): void {
                $ids = $issues->modelKeys();

                if ($discard) {
                    // Die Fingerabdrücke **vor** dem Löschen einsammeln: danach
                    // ist der Weg vom Eintrag zu seinen Gruppen weg, und mit ihm
                    // das einzige Wiedererkennungsmerkmal.
                    foreach ($this->fingerprintsOf($ids) as [$projectId, $issueId, $hash]) {
                        $fingerprints[] = [$projectId, $hash, $issues->find($issueId)?->title];
                    }
                }

                $this->log($issues, $type, $discard ? ['discard' => true] : null);

                $count += Issue::query()->whereIn('id', $ids)->delete();
            }, 'issues.id', 'id');

        $added = [];

        foreach ($fingerprints as [$projectId, $hash, $title]) {
            IssueDiscard::add($projectId, $hash, $title, $this->actor?->id);

            $added[] = ['project' => $projectId, 'fingerprint' => $hash];
        }

        // Kein Rückweg für das Löschen selbst: der Eintrag ist weg, und ein
        // „Rückgängig", das ihn nicht wiederbringt, wäre eine Lüge. Was sich
        // zurücknehmen lässt, ist die Verwerfung — und genau das ist der Teil,
        // den jemand versehentlich auslöst.
        return new IssueActionResult($count, discards: $added);
    }

    /**
     * Nimmt Fingerabdrücke wieder aus der Verwerfungsliste.
     *
     * @param  list<array{project: int, fingerprint: string}>  $entries
     */
    public function undiscard(array $entries): int
    {
        foreach ($entries as $entry) {
            IssueDiscard::remove($entry['project'], $entry['fingerprint']);
        }

        return count($entries);
    }

    /**
     * Beendet eine Stummschaltung, deren Bedingung eingetreten ist.
     *
     * Läuft bei der Aufnahme und damit ohne handelndes Konto — der Vermerk trägt
     * deshalb keinen Namen. Aufgerufen wird sie aus
     * {@see AggregateIssue}, nachdem das
     * Ereignis gezählt ist: die Bedingung misst gezählte Ereignisse, und vorher
     * gemessen wäre sie um eins zu früh.
     *
     * Der bedingte `update` ist kein Feinschliff: dieselbe Meldung kann aus
     * mehreren Arbeitern gleichzeitig kommen, und ohne die Bedingung auf
     * `status` stünde am Ende ein Eintrag offen, den jemand in derselben Sekunde
     * von Hand erledigt hat.
     */
    public static function expireIgnore(Issue $issue, int $timesSeen, int $usersSeen): bool
    {
        if ($issue->status !== IssueStatus::Ignored) {
            return false;
        }

        $now = CarbonImmutable::now();
        $outcome = IgnoreCondition::fromIssue($issue)->evaluate($timesSeen, $usersSeen, $now);

        if ($outcome === IgnoreOutcome::Keep) {
            return false;
        }

        if ($outcome === IgnoreOutcome::Restart) {
            // Das Fenster beginnt neu; der Eintrag bleibt still. Der
            // Ausgangsstand wandert mit, sonst wäre das nächste Fenster eines
            // über die Summe aller vorigen.
            Issue::query()
                ->whereKey($issue->id)
                ->where('status', IssueStatus::Ignored)
                ->update([
                    'ignored_at' => $now,
                    'ignore_times_seen' => $timesSeen,
                    'ignore_users_seen' => $usersSeen,
                ]);

            return false;
        }

        $woken = Issue::query()
            ->whereKey($issue->id)
            ->where('status', IssueStatus::Ignored)
            ->update([
                'status' => IssueStatus::Unresolved,
                ...self::clearedIgnore(),
                'updated_at' => $now,
            ]);

        if ($woken === 0) {
            return false;
        }

        IssueActivity::query()->create([
            'issue_id' => $issue->id,
            'project_id' => $issue->project_id,
            'user_id' => null,
            'actor_name' => null,
            'type' => IssueActivityType::IgnoreExpired,
            'data' => ['times_seen' => $timesSeen, 'users_seen' => $usersSeen],
        ]);

        $issue->status = IssueStatus::Unresolved;

        return true;
    }

    /**
     * Öffnet einen erledigten Eintrag wieder, weil er zurückgekommen ist (S8).
     *
     * Der Zwilling von {@see expireIgnore()}: bei der Aufnahme, ohne handelndes
     * Konto, mit einem bedingten `update` als einziger Absicherung. Die
     * Bedingung auf `status` und `resolved_at` ist hier sogar die wichtigere
     * Hälfte — die Meldungen eines Ausfalls laufen gleichzeitig durch mehrere
     * Arbeiter, und jeder von ihnen kommt mit demselben Urteil an. Sie sorgt
     * dafür, dass genau **einer** den Eintrag aufmacht und genau **ein**
     * Vermerk im Verlauf steht; die übrigen gehen leer aus und melden das auch
     * ({@see $issue} bleibt bei ihnen unverändert).
     *
     * `resolved_at` steht mit in der Bedingung und nicht nur `status`: zwischen
     * dem Urteil und diesem `update` kann jemand den Eintrag von Hand wieder
     * erledigt haben, und dann bezieht sich das Urteil auf eine Erledigung, die
     * es nicht mehr gibt.
     *
     * **Zuweisung, Kommentare und Zähler bleiben unberührt.** Ein Rückfall ist
     * derselbe Fehler und kein neuer — was an ihm hängt, gehört weiter zu ihm.
     *
     * @param  Release|null  $seenIn  Die Fassung, in der er zurückkam — für den
     *                                Verlauf und für die Anzeige.
     * @return bool `true`, wenn **dieser** Aufruf ihn aufgemacht hat.
     */
    public static function reopenRegression(Issue $issue, ?Release $seenIn): bool
    {
        $now = CarbonImmutable::now();

        $reopened = Issue::query()
            ->whereKey($issue->id)
            ->where('status', IssueStatus::Resolved)
            ->where('resolved_at', $issue->resolved_at)
            ->update([
                'status' => IssueStatus::Unresolved,
                'resolved_at' => null,
                'resolved_by_id' => null,
                'resolved_in_release_id' => null,
                'resolved_in_next_release' => false,
                'regressed_at' => $now,
                'regressed_in_release_id' => $seenIn?->id,
                'updated_at' => $now,
            ]);

        if ($reopened === 0) {
            return false;
        }

        IssueActivity::query()->create([
            'issue_id' => $issue->id,
            'project_id' => $issue->project_id,
            'user_id' => null,
            'actor_name' => null,
            'type' => IssueActivityType::Regressed,
            // Die Version als **Text** und nicht als Verweis: der Verlauf ist
            // unveränderlich, und was er sagt, soll auch dann noch stimmen,
            // wenn die Auslieferung längst aufgeräumt ist.
            'data' => $seenIn === null ? null : ['release' => $seenIn->version],
        ]);

        $issue->status = IssueStatus::Unresolved;
        $issue->regressed_at = $now;
        $issue->regressed_in_release_id = $seenIn?->id;

        return true;
    }

    /**
     * Der gemeinsame Ablauf aller Zustandsaktionen: in Blöcken fortschreiben,
     * je Block einmal den Verlauf schreiben, die Kennungen für den Rückweg
     * mitnehmen.
     *
     * @param  Builder<Issue>  $query
     * @param  callable(list<int>): int  $write
     * @param  array<string, mixed>|null  $data
     */
    private function apply(Builder $query, callable $write, IssueActivityType $type, ?array $data = null): IssueActionResult
    {
        $count = 0;
        $undo = [];

        self::rows($query)
            ->chunkById(self::CHUNK, function (Collection $issues) use (&$count, &$undo, $write, $type, $data): void {
                $ids = $issues->modelKeys();

                $count += $write($ids);

                $this->log($issues, $type, $data);

                if (count($undo) < self::UNDO_LIMIT) {
                    $undo = array_slice([...$undo, ...$ids], 0, self::UNDO_LIMIT);
                }
            }, 'issues.id', 'id');

        // Der Rückweg wird nur angeboten, wenn er **alle** betroffenen Einträge
        // erreicht. Eine Schaltfläche, die 200 von 12.480 zurücknimmt, wäre
        // schlimmer als keine: sie sieht aus, als hätte sie alles erledigt.
        return new IssueActionResult($count, $count <= self::UNDO_LIMIT ? $undo : []);
    }

    /**
     * Merken und Abonnieren — dieselbe Form, zwei Tabellen.
     *
     * `insertOrIgnore` statt eines Nachsehens davor: zweimaliges Merken ist kein
     * Fehler, sondern derselbe Wunsch ein zweites Mal, und der eindeutige Index
     * über (`issue_id`, `user_id`) sagt das der Datenbank bereits. Ein `exists()`
     * davor wäre nur eine Momentaufnahme.
     *
     * @param  Builder<Issue>  $query
     */
    private function attach(Builder $query, string $table, bool $on, IssueActivityType $type): IssueActionResult
    {
        if ($this->actor === null) {
            return new IssueActionResult(0);
        }

        $userId = $this->actor->id;
        $now = CarbonImmutable::now();
        $count = 0;
        $undo = [];

        self::rows($query)
            ->chunkById(self::CHUNK, function (Collection $issues) use (&$count, &$undo, $table, $on, $userId, $now, $type): void {
                $ids = $issues->modelKeys();

                if ($on) {
                    DB::table($table)->insertOrIgnore(array_map(
                        static fn (int $id): array => [
                            'issue_id' => $id,
                            'user_id' => $userId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $ids,
                    ));
                } else {
                    DB::table($table)->whereIn('issue_id', $ids)->where('user_id', $userId)->delete();
                }

                $count += count($ids);

                $this->log($issues, $type);

                if (count($undo) < self::UNDO_LIMIT) {
                    $undo = array_slice([...$undo, ...$ids], 0, self::UNDO_LIMIT);
                }
            }, 'issues.id', 'id');

        return new IssueActionResult($count, $count <= self::UNDO_LIMIT ? $undo : []);
    }

    /**
     * Schreibt den Verlauf eines Blocks in **einer** Einfügung.
     *
     * Der Name des Handelnden wird mitgeschrieben und nicht nur verwiesen: ein
     * gelöschtes Konto darf den Verlauf nicht anonymisieren. Dieselbe Wahl wie
     * im Änderungsprotokoll.
     *
     * @param  Collection<int, Issue>  $issues
     * @param  array<string, mixed>|null  $data
     */
    private function log(Collection $issues, IssueActivityType $type, ?array $data = null): void
    {
        if ($issues->isEmpty()) {
            return;
        }

        $now = CarbonImmutable::now();
        $encoded = $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE);

        IssueActivity::query()->insert($issues->map(fn (Issue $issue): array => [
            // Ein Vermerk, der den Eintrag überlebt, darf nicht auf ihn zeigen:
            // der Fremdschlüssel würde ihn beim Löschen ohnehin leeren, und
            // dazwischen stünde eine Zeile mit einem Verweis ins Leere.
            'issue_id' => $type->outlivesIssue() ? null : $issue->id,
            'project_id' => $issue->project_id,
            'user_id' => $this->actor?->id,
            'actor_name' => $this->actor?->name,
            'type' => $type->value,
            'data' => $encoded,
            'created_at' => $now,
        ])->all());
    }

    /**
     * Die Fingerabdrücke der Gruppen dieser Einträge.
     *
     * Je Eintrag genau einer, solange S9 das Zusammenführen nicht gebracht hat;
     * danach mehrere — und dann verwirft „löschen und verwerfen" alle
     * zusammengeführten Untergruppen. Das ist die Absicht: gelöscht wurde der
     * Eintrag, und er bestand aus ihnen. Eine Liste und keine Zuordnung je
     * Eintrag, damit genau dieser Fall nicht auf einen Fingerabdruck
     * zusammenfällt.
     *
     * @param  list<int>  $issueIds
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function fingerprintsOf(array $issueIds): array
    {
        return DB::table('event_groups')
            ->select(['issue_id', 'project_id', 'fingerprint'])
            ->whereIn('issue_id', $issueIds)
            ->get()
            ->map(static fn (object $row): array => [
                (int) $row->project_id,
                (int) $row->issue_id,
                (string) $row->fingerprint,
            ])
            ->all();
    }

    /**
     * Die Abfrage, wie sie eine Aktion braucht: nur die Spalten, um die es geht,
     * und ohne Vorausladen.
     *
     * Die Liste lädt Projekt, Organisation und die betroffenen Versionen mit,
     * weil sie die anzeigt. Eine Aktion zeigt nichts an — sie schreibt. Das
     * Vorausladen stehen zu lassen wäre je Block eine Handvoll Abfragen für
     * Werte, die niemand liest, und bei zwölftausend Einträgen sind das
     * Hunderte.
     *
     * @param  Builder<Issue>  $query
     * @param  list<string>  $extra
     * @return Builder<Issue>
     */
    private static function rows(Builder $query, array $extra = []): Builder
    {
        return $query->clone()
            ->setEagerLoads([])
            ->select(['issues.id', 'issues.project_id', ...$extra]);
    }

    /**
     * Die Spalten, die eine Stummschaltung zurücklassen soll.
     *
     * An einer Stelle, weil sie an dreien gebraucht werden — und weil eine
     * vergessene Spalte hier keinen Fehler ergibt, sondern eine Bedingung, die
     * beim nächsten Stummschalten aus einer alten Aktion nachwirkt.
     *
     * @return array<string, mixed>
     */
    private static function clearedIgnore(): array
    {
        return [
            'ignored_at' => null,
            'ignored_by_id' => null,
            'ignore_count' => null,
            'ignore_window_minutes' => null,
            'ignore_users' => null,
            'ignore_times_seen' => null,
            'ignore_users_seen' => null,
        ];
    }

    /**
     * Die Spalten, die ein Rückfall zurücklassen soll.
     *
     * Er endet mit dem nächsten Zustandswechsel von Hand — erledigen,
     * stummschalten, wieder öffnen. „Wieder aufgetreten" beschreibt, wie der
     * Eintrag in seinen jetzigen Zustand gekommen ist; sobald jemand ihn ändert,
     * beschreibt es nichts mehr, und die Ansicht „Wieder aufgetreten" führte ihn
     * sonst für immer.
     *
     * @return array<string, mixed>
     */
    private static function clearedRegression(): array
    {
        return [
            'regressed_at' => null,
            'regressed_in_release_id' => null,
        ];
    }
}
