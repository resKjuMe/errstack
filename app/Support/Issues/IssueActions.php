<?php

namespace App\Support\Issues;

use App\Enums\IgnoreOutcome;
use App\Enums\IssueActivityType;
use App\Enums\IssueIgnoreMode;
use App\Enums\IssuePriority;
use App\Enums\IssueResolveMode;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueDiscard;
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
                'resolved_in_release_id' => $mode === IssueResolveMode::CurrentRelease
                    ? DB::raw('last_release_id')
                    : null,
                'resolved_in_next_release' => $mode === IssueResolveMode::NextRelease,
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
     * Die Wichtigkeit von Hand setzen — oder sie wieder der Ableitung
     * überlassen (S11).
     *
     * **Von Hand gesetzt heißt festgestellt.** `priority_locked` ist die einzige
     * Auskunft, an der die Ableitung erkennen kann, dass jemand widersprochen
     * hat; ohne sie hätte sie nur die Wahl, entweder jede Einordnung von Hand im
     * nächsten Durchlauf zu überschreiben oder nie wieder etwas anzufassen, was
     * einmal von der Vorgabe abweicht ({@see IssuePrioritySweep}).
     *
     * **Der Weg zurück ist derselbe Knopf.** `null` heißt „automatisch": der
     * Schalter fällt, die Stufe bleibt vorerst stehen und der nächste Durchlauf
     * rechnet sie neu. Sie dabei sofort auf die Vorgabe zurückzusetzen wäre die
     * lautere und die falsche Wahl — zwischen Klick und Durchlauf stünde eine
     * Zahl da, die niemand behauptet hat.
     *
     * @param  Builder<Issue>  $query
     */
    public function prioritize(Builder $query, ?IssuePriority $priority): IssueActionResult
    {
        $now = CarbonImmutable::now();

        return $this->apply(
            $query,
            fn (array $ids): int => Issue::query()->whereIn('id', $ids)->update(array_filter([
                'priority' => $priority?->value,
                'priority_locked' => $priority !== null,
                'updated_at' => $now,
            ], static fn (mixed $value): bool => $value !== null)),
            IssueActivityType::PriorityChanged,
            $priority === null
                ? ['mode' => 'auto']
                : ['mode' => 'manual', 'priority' => $priority->value],
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
     * Holt einen stummgeschalteten Eintrag zurück, der aus dem Ruder gelaufen
     * ist (S11).
     *
     * Der Zwilling von {@see self::expireIgnore()} und aus denselben Teilen
     * gebaut — bedingter `update` auf `status`, Vermerk ohne Konto, dieselbe
     * geleerte Bedingung. Der Unterschied liegt allein im Anlass: dort ist
     * eingetreten, was jemand vereinbart hat, hier hat niemand etwas vereinbart
     * ({@see IssueEscalation}). Deshalb ein eigener Vermerk und ein eigener
     * Zeitstempel — `escalated_at` ist der Grund, warum dieselbe Welle nicht in
     * jedem Durchlauf erneut gemeldet wird.
     *
     * Läuft im Hintergrund-Durchlauf und damit ohne handelndes Konto.
     */
    public static function escalate(Issue $issue, IssueEscalation $escalation, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        $woken = Issue::query()
            ->whereKey($issue->id)
            // Dieselbe Bedingung wie beim Ablauf der Stummschaltung: zwischen
            // dem Errechnen und dem Schreiben kann jemand den Eintrag von Hand
            // erledigt oder wieder geöffnet haben.
            ->where('status', IssueStatus::Ignored)
            ->update([
                'status' => IssueStatus::Unresolved,
                ...self::clearedIgnore(),
                'escalated_at' => $now,
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
            'type' => IssueActivityType::Escalated,
            'data' => [
                'observed' => $escalation->observed,
                'expected' => round($escalation->expected, 1),
                'factor' => $escalation->factor(),
            ],
        ]);

        $issue->status = IssueStatus::Unresolved;
        $issue->escalated_at = $now;

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
}
