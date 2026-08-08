<?php

namespace App\Support\Tags;

use App\Models\Event;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Models\IssueTag;
use App\Models\IssueTagKey;
use App\Models\ProjectTag;
use App\Models\ProjectTagKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Schreibt die Merkmal-Zähler eines Ereignisses fort — auf beiden Ebenen.
 *
 * Der Gegenpart zu {@see IssueCount}: dort wird gezählt, **wann**
 * ein Fehler auftrat, hier **womit**. Beides passiert beim Eingang und aus
 * demselben Grund — die Antwort über die Einzelereignisse zu rechnen ist bei
 * einem Fehler mit einer Million Auftreten keine Abfrage mehr, sondern ein
 * Auftrag.
 *
 * **Sperrfrei, wie alles auf diesem Weg.** Jede Fortschreibung ist eine einzelne
 * SQL-Anweisung, in der die Datenbank den alten Wert selbst einsetzt. Bei einem
 * Ausfall trägt jede gleichzeitig verarbeitete Meldung dieselben Merkmale —
 * `browser = Chrome 124`, `server_name = web-07` —, und damit wäre genau diese
 * eine Zeile die, auf die alle Arbeiter gleichzeitig schreiben. Ein
 * Lesen-Ändern-Schreiben wäre dort das „verlorene Hochzählen" nicht als
 * Ausnahme, sondern als Regelfall.
 *
 * **Doppelt zählen kann es nicht.** Der Aufruf hängt an
 * {@see Issue::record()} und damit hinter dem Anspruch auf das Zählen
 * ({@see Event::claimForCounting()}): dieselbe Meldung darf ein zweites Mal
 * durch die Kette laufen, ihre Zähler steigen aber nur einmal.
 *
 * **Vier bis sechs Anweisungen je Ereignis**, und zwar unabhängig davon, wie
 * viele Merkmale es trägt: die Werte werden in einer Anweisung angelegt und in
 * einer weiteren hochgezählt, nicht einzeln. Die beiden zusätzlichen fallen nur
 * an, wenn ein Wert zum ersten Mal auftritt — nach dem Warmlaufen also selten.
 */
final class TagAggregates
{
    /**
     * Wie viele verschiedene Werte je Merkmal aufgehoben werden.
     *
     * Die Begrenzung ist die Zusage dieser Aufgabe, und sie ist keine
     * Sparsamkeit: ein Merkmal wie `url` oder eine selbst gesetzte Marke mit
     * einer Kennung darin hat je Aufruf einen anderen Wert. Ohne Grenze wüchse
     * die Tabelle mit der Zahl der Ereignisse statt mit der Zahl der
     * Unterscheidungen — und eine Verteilung mit einer Million Balken ist keine.
     *
     * Aufgehoben werden die **zuerst gesehenen**, nicht die häufigsten. Die
     * häufigsten wären richtiger und nur mit einem Verdrängungsverfahren zu
     * haben, das bei jedem Ereignis liest, vergleicht und löscht — im
     * Eingangsweg. Was dabei herausfällt, geht nicht verloren: der Nenner am
     * Merkmal zählt weiter, und die Anzeige weist die Lücke als „übrige" aus.
     */
    public const MAX_VALUES_PER_KEY = 100;

    /**
     * Wie viele verschiedene Merkmale je Fehler und je Projekt aufgehoben
     * werden.
     *
     * Dieselbe Sorge eine Ebene höher: die Normalisierung begrenzt die Marken
     * **je Meldung** (100), nicht über alle Meldungen hinweg. Eine Anwendung,
     * die den Namen der Marke aus einer Kennung baut, legt sonst je Ereignis ein
     * neues Merkmal an.
     */
    public const MAX_KEYS = 200;

    /**
     * Nimmt die Merkmale eines Ereignisses in die Zähler auf.
     */
    public static function record(Issue $issue, Event $event): void
    {
        $tags = EventTags::forEvent($event);

        if ($tags === []) {
            return;
        }

        $occurred = CarbonImmutable::parse($event->occurred_at)->utc();

        self::scope(
            keyTable: (new IssueTagKey)->getTable(),
            valueTable: (new IssueTag)->getTable(),
            ownerColumn: 'issue_id',
            ownerId: $issue->id,
            extra: ['project_id' => $issue->project_id],
            tags: $tags,
            occurred: $occurred,
        );

        self::scope(
            keyTable: (new ProjectTagKey)->getTable(),
            valueTable: (new ProjectTag)->getTable(),
            ownerColumn: 'project_id',
            ownerId: $issue->project_id,
            extra: [],
            tags: $tags,
            occurred: $occurred,
        );
    }

    /**
     * Eine Ebene: Merkmal-Zähler, dann Wert-Zähler.
     *
     * Beide Ebenen sind bis auf den Besitzer gleich, deshalb ein Verfahren und
     * nicht zwei. Die Alternative wäre dieselbe Folge von Anweisungen zweimal
     * hingeschrieben — und genau dort läuft eine Zählung auseinander, wenn
     * später eine der beiden angepasst wird.
     *
     * @param  array<string, int>  $extra  zusätzliche Spalten beim Anlegen
     * @param  array<string, string>  $tags
     */
    private static function scope(
        string $keyTable,
        string $valueTable,
        string $ownerColumn,
        int $ownerId,
        array $extra,
        array $tags,
        CarbonImmutable $occurred,
    ): void {
        $stamp = Carbon::now()->format('Y-m-d H:i:s');
        $keys = array_keys($tags);

        // Welche Werte gibt es schon? Der Blick lohnt sich, weil er zwei Fragen
        // auf einmal beantwortet: was hochzuzählen ist und was neu ist — und
        // nur für das Neue muss die Obergrenze überhaupt geprüft werden.
        $known = self::knownPairs($valueTable, $ownerColumn, $ownerId, $tags);

        $fresh = array_diff_assoc($tags, $known);

        if ($fresh !== []) {
            self::createKeys($keyTable, $ownerColumn, $ownerId, $extra, $keys, $stamp);

            $tags = $known + self::createValues(
                $keyTable, $valueTable, $ownerColumn, $ownerId, $extra, $fresh, $occurred, $stamp,
            );
        }

        // Die Merkmale werden über **alle** Namen dieses Ereignisses gezählt,
        // die Werte nur über die, die auch eine Zeile haben. Der Unterschied ist
        // der Sinn der beiden Tabellen: was die Obergrenze aussortiert hat,
        // fehlt bei den Werten und steht trotzdem im Nenner.
        self::countKeys($keyTable, $ownerColumn, $ownerId, $keys, $stamp);

        if ($tags !== []) {
            self::countValues($valueTable, $ownerColumn, $ownerId, $tags, $occurred, $stamp);
        }
    }

    /**
     * Die Werte dieses Ereignisses, die schon eine Zeile haben.
     *
     * Gefragt wird ausdrücklich nach **diesen** Paaren und nicht nach „allen
     * Werten dieser Merkmale": Letzteres wären bis zu
     * {@see self::MAX_VALUES_PER_KEY} Zeilen je Merkmal, für eine Auskunft über
     * eine Handvoll.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function knownPairs(string $valueTable, string $ownerColumn, int $ownerId, array $tags): array
    {
        $rows = DB::table($valueTable)
            ->where($ownerColumn, $ownerId)
            ->where(function ($query) use ($tags): void {
                foreach ($tags as $key => $value) {
                    $query->orWhere(fn ($q) => $q->where('tag_key', $key)->where('tag_value', $value));
                }
            })
            ->get(['tag_key', 'tag_value']);

        $known = [];

        foreach ($rows as $row) {
            $known[(string) $row->tag_key] = (string) $row->tag_value;
        }

        return $known;
    }

    /**
     * Legt die Merkmal-Zeilen an, die fehlen — mit Zählerstand null.
     *
     * Angelegt und hochgezählt sind zwei Schritte, wie bei den Zeitreihen (I6):
     * `insertOrIgnore` mit dem Stand eins verlöre eine Zählung, sobald zwei
     * Arbeiter dieselbe Zeile im selben Augenblick anlegen wollen — einer von
     * beiden wird stillschweigend übergangen. Mit null angelegt und danach
     * hochgezählt, zählen beide.
     *
     * Über {@see self::MAX_KEYS} hinaus wird nichts mehr angelegt. Der Zählstand
     * dafür wird nur hier gelesen, also nur dann, wenn ein Merkmal überhaupt neu
     * sein kann.
     *
     * @param  array<string, int>  $extra
     * @param  list<string>  $keys
     */
    private static function createKeys(
        string $keyTable,
        string $ownerColumn,
        int $ownerId,
        array $extra,
        array $keys,
        string $stamp,
    ): void {
        $existing = DB::table($keyTable)
            ->where($ownerColumn, $ownerId)
            ->whereIn('tag_key', $keys)
            ->pluck('tag_key')
            ->all();

        $missing = array_values(array_diff($keys, array_map(strval(...), $existing)));

        if ($missing === []) {
            return;
        }

        $room = self::MAX_KEYS - DB::table($keyTable)->where($ownerColumn, $ownerId)->count();

        if ($room <= 0) {
            return;
        }

        DB::table($keyTable)->insertOrIgnore(array_map(
            static fn (string $key): array => $extra + [
                $ownerColumn => $ownerId,
                'tag_key' => $key,
                'times_seen' => 0,
                'value_count' => 0,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ],
            array_slice($missing, 0, $room),
        ));
    }

    /**
     * Legt die neuen Werte an, soweit die Obergrenze es zulässt.
     *
     * @param  array<string, int>  $extra
     * @param  array<string, string>  $fresh
     * @return array<string, string> die Werte, die tatsächlich eine Zeile haben
     */
    private static function createValues(
        string $keyTable,
        string $valueTable,
        string $ownerColumn,
        int $ownerId,
        array $extra,
        array $fresh,
        CarbonImmutable $occurred,
        string $stamp,
    ): array {
        $counts = DB::table($keyTable)
            ->where($ownerColumn, $ownerId)
            ->whereIn('tag_key', array_keys($fresh))
            ->pluck('value_count', 'tag_key');

        $at = $occurred->format('Y-m-d H:i:s');
        $created = [];

        foreach ($fresh as $key => $value) {
            $count = $counts[$key] ?? null;

            // Kein Merkmal-Zähler heißt: die Obergrenze der Merkmale war
            // erreicht, das Merkmal wurde gar nicht erst angelegt. Dann gibt es
            // auch keinen Nenner, unter dem dieser Wert stünde.
            if ($count === null || $count >= self::MAX_VALUES_PER_KEY) {
                continue;
            }

            $inserted = DB::table($valueTable)->insertOrIgnore([$extra + [
                $ownerColumn => $ownerId,
                'tag_key' => $key,
                'tag_value' => $value,
                'times_seen' => 0,
                'first_seen' => $at,
                'last_seen' => $at,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]]);

            $created[$key] = $value;

            // Nur was wirklich angelegt wurde, zählt gegen die Obergrenze — war
            // ein anderer Arbeiter schneller, hat er bereits hochgezählt.
            if ($inserted === 1) {
                DB::update(
                    'update '.$keyTable.' set value_count = value_count + 1, updated_at = ? '
                    .'where '.$ownerColumn.' = ? and tag_key = ?',
                    [$stamp, $ownerId, $key],
                );
            }
        }

        return $created;
    }

    /**
     * Zählt das Ereignis auf jedes seiner Merkmale — der Nenner der
     * Prozentangabe.
     *
     * Er steigt auch dann, wenn der Wert selbst nicht aufgehoben wurde. Genau
     * das macht die Angabe ehrlich: „73 % Chrome" heißt dann „73 % der
     * Ereignisse, die überhaupt einen Browser gemeldet haben", und der Rest
     * fehlt sichtbar, statt sich unter den vorhandenen Werten zu verteilen.
     *
     * @param  list<string>  $keys
     */
    private static function countKeys(string $keyTable, string $ownerColumn, int $ownerId, array $keys, string $stamp): void
    {
        DB::update(
            'update '.$keyTable.' set times_seen = times_seen + 1, updated_at = ? '
            .'where '.$ownerColumn.' = ? and tag_key in ('.implode(', ', array_fill(0, count($keys), '?')).')',
            [$stamp, $ownerId, ...$keys],
        );
    }

    /**
     * Zählt das Ereignis auf jeden seiner Werte — in **einer** Anweisung.
     *
     * Erstes und letztes Auftreten mit `case when`, aus demselben Grund wie am
     * Fehler-Eintrag ({@see Issue::record()}): Meldungen kommen nicht in ihrer
     * zeitlichen Reihenfolge an. Ein SDK, das nach einer Netztrennung seine
     * Warteschlange leert, würde `last_seen = ?` sonst zurückdatieren — und
     * „diese Fassung ist seit gestern still" wäre eine Aussage über die
     * Reihenfolge der Zustellung statt über die Wirklichkeit.
     *
     * @param  array<string, string>  $tags
     */
    private static function countValues(
        string $valueTable,
        string $ownerColumn,
        int $ownerId,
        array $tags,
        CarbonImmutable $occurred,
        string $stamp,
    ): void {
        $at = $occurred->format('Y-m-d H:i:s');

        $pairs = [];
        $bindings = [$at, $at, $at, $at, $stamp, $ownerId];

        foreach ($tags as $key => $value) {
            $pairs[] = '(tag_key = ? and tag_value = ?)';
            $bindings[] = $key;
            $bindings[] = $value;
        }

        DB::update(
            'update '.$valueTable.' set '
            .'times_seen = times_seen + 1, '
            .'first_seen = case when first_seen > ? then ? else first_seen end, '
            .'last_seen = case when last_seen < ? then ? else last_seen end, '
            .'updated_at = ? '
            .'where '.$ownerColumn.' = ? and ('.implode(' or ', $pairs).')',
            $bindings,
        );
    }
}
