<?php

namespace App\Support\Issues;

use App\Enums\EventLevel;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Models\IssueTag;
use App\Support\Performance\TransactionSearch;
use App\Support\Search\Ast\Condition;
use App\Support\Search\Ast\FreeText;
use App\Support\Search\Comparator;
use App\Support\Search\FieldResolver;
use App\Support\Search\SearchSyntaxException;
use App\Support\Tags\EventTags;
use Carbon\CarbonImmutable;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;

/**
 * Was die Felder der Suchsprache in der **Fehlerliste** bedeuten.
 *
 * Hier endet die Sprache und beginnt das Schema. Drei Sorten von Feldern:
 *
 *   - **Spalten am Eintrag** — `is:`, `level:`, `priority:`, `timesSeen:`,
 *     `usersSeen:`, `firstSeen:`, `lastSeen:`. Sie kosten nichts, weil sie
 *     dieselben Zähler lesen, die die Liste ohnehin anzeigt.
 *   - **Merkmale** — alles, was hier nicht steht: `browser:Chrome`,
 *     `environment:production`, `server_name:web-07`. Ein unbekanntes Feld ist
 *     deshalb **kein** Fehler; es ist die Regel, und das ist der Grund, warum
 *     die Sprache ohne eine Liste erlaubter Namen auskommt.
 *   - **Ereignisse** — `user.email:` und seine Geschwister, als einzige. Wer sie
 *     benutzt, verlässt die Zusage der Fehlerliste, nur über Zähler zu gehen
 *     ({@see IssueList}); das steht dort ausdrücklich und ist hier der Preis
 *     dafür, dass „welche Fehler hatte dieser Kunde?" überhaupt beantwortbar
 *     ist. Nutzer-Angaben sind bewusst **keine** Merkmale
 *     ({@see EventTags}) — sie würden sonst das Aufräumen der
 *     Ereignisse überleben.
 *
 * **Was es noch nicht gibt, wird benannt und nicht erfunden.** `assigned:`,
 * `bookmarks:`, `is:for_review` und `is:regressed` gehören zur Sprache, aber die
 * Zuständigkeit (S7), die Merkzettel (S6) und die Rückfallerkennung (S8) sind
 * eigene Aufgaben. Sie schränken deshalb nichts ein und werden zurückgemeldet —
 * eine Liste, die so tut, als hätte sie „mir zugewiesen" ausgewertet, ist
 * schlimmer als eine, die sagt, dass sie es nicht konnte.
 */
final class IssueFields implements FieldResolver
{
    /**
     * Fluchtzeichen für die Platzhalter von `LIKE` — aus denselben Gründen kein
     * Rückstrich wie in {@see TransactionSearch}: MySQL
     * deutet ihn zweimal um, SQLite kennt ihn dort gar nicht.
     */
    private const ESCAPE = '!';

    /**
     * Die Zustände, die `is:` heute beantworten kann.
     *
     * @var array<string, IssueStatus>
     */
    private const STATES = [
        'unresolved' => IssueStatus::Unresolved,
        'resolved' => IssueStatus::Resolved,
        'ignored' => IssueStatus::Ignored,
    ];

    /**
     * Zustände, die es in der Sprache gibt und in den Daten noch nicht.
     *
     * @var list<string>
     */
    private const PENDING_STATES = ['assigned', 'unassigned', 'for_review', 'regressed'];

    /**
     * Felder, die es in der Sprache gibt und in den Daten noch nicht.
     *
     * @var list<string>
     */
    private const PENDING_FIELDS = ['assigned', 'bookmarks'];

    /**
     * Die Nutzer-Angaben am Ereignis, die sich abfragen lassen — Feldname der
     * Suche auf Feld im gespeicherten Datensatz.
     *
     * @var array<string, string>
     */
    private const USER_FIELDS = [
        'user.email' => 'email',
        'user.id' => 'id',
        'user.username' => 'username',
        'user.ip' => 'ip_address',
    ];

    /** @var list<string> */
    private array $unavailable = [];

    /**
     * @param  string  $timezone  Zeitzone des Betrachters — Datumsangaben ohne
     *                            Uhrzeit meinen seinen Tag und nicht den in UTC.
     */
    public function __construct(private readonly string $timezone = 'UTC') {}

    /**
     * Die Felder mit eigener Bedeutung, für die Vorschläge des Suchfeldes.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        return [
            'is',
            'level',
            'priority',
            'timesSeen',
            'usersSeen',
            'firstSeen',
            'lastSeen',
            'release',
            'firstRelease',
            ...array_keys(self::USER_FIELDS),
            ...self::PENDING_FIELDS,
        ];
    }

    /**
     * @return list<string>
     */
    public function unavailable(): array
    {
        return array_values(array_unique($this->unavailable));
    }

    public function condition(Condition $condition): ?Closure
    {
        $key = $condition->key();

        if (in_array($key, self::PENDING_FIELDS, true)) {
            $this->unavailable[] = $condition->field.':'.$condition->value;

            return null;
        }

        if (isset(self::USER_FIELDS[$key])) {
            return $this->user(self::USER_FIELDS[$key], $condition);
        }

        return match ($key) {
            'is' => $this->state($condition),
            'level' => $this->enumColumn($condition, 'level', array_column(EventLevel::cases(), 'value')),
            'priority' => $this->enumColumn($condition, 'priority', array_column(IssuePriority::cases(), 'value')),
            'timesseen' => $this->number($condition, 'times_seen'),
            'usersseen' => $this->number($condition, 'users_seen'),
            'firstseen' => $this->moment($condition, 'first_seen'),
            'lastseen' => $this->moment($condition, 'last_seen'),
            'release' => $this->release($condition, ['firstRelease', 'lastRelease']),
            'firstrelease' => $this->release($condition, ['firstRelease']),
            default => $this->tag($condition),
        };
    }

    public function freeText(FreeText $text): Closure
    {
        $pattern = '%'.self::escape($text->text).'%';

        return function (Builder $query) use ($pattern): void {
            $query->where(function (Builder $inner) use ($pattern): void {
                $inner
                    ->whereRaw('title like ? escape \''.self::ESCAPE.'\'', [$pattern])
                    ->orWhereRaw('culprit like ? escape \''.self::ESCAPE.'\'', [$pattern]);
            });
        };
    }

    /**
     * `is:` — der Zustand des Eintrags.
     */
    private function state(Condition $condition): ?Closure
    {
        $value = mb_strtolower($condition->value);

        if (in_array($value, self::PENDING_STATES, true)) {
            $this->unavailable[] = $condition->field.':'.$condition->value;

            return null;
        }

        $status = self::STATES[$value] ?? null;

        if ($status === null) {
            throw new SearchSyntaxException(
                __('search.errors.unknown_value', [
                    'field' => $condition->field,
                    'value' => $condition->value,
                    'allowed' => implode(', ', [...array_keys(self::STATES), ...self::PENDING_STATES]),
                ]),
                $condition->valuePosition,
                $condition->value,
            );
        }

        self::rejectComparator($condition);

        return fn (Builder $query) => $query->where('status', $status);
    }

    /**
     * Eine Spalte, die nur eine feste Auswahl an Werten kennt.
     *
     * @param  list<string>  $allowed
     */
    private function enumColumn(Condition $condition, string $column, array $allowed): Closure
    {
        self::rejectComparator($condition);

        $value = mb_strtolower($condition->value);

        if (! in_array($value, $allowed, true)) {
            throw new SearchSyntaxException(
                __('search.errors.unknown_value', [
                    'field' => $condition->field,
                    'value' => $condition->value,
                    'allowed' => implode(', ', $allowed),
                ]),
                $condition->valuePosition,
                $condition->value,
            );
        }

        return fn (Builder $query) => $query->where($column, $value);
    }

    /**
     * Ein Zähler — `timesSeen:>100`.
     */
    private function number(Condition $condition, string $column): Closure
    {
        if (preg_match('/^\d+$/', $condition->value) !== 1) {
            throw new SearchSyntaxException(
                __('search.errors.not_a_number', [
                    'field' => $condition->field,
                    'value' => $condition->value,
                ]),
                $condition->valuePosition,
                $condition->value,
            );
        }

        $operator = $condition->comparator->sql();
        $number = (int) $condition->value;

        return fn (Builder $query) => $query->where($column, $operator, $number);
    }

    /**
     * Ein Zeitpunkt — `firstSeen:>2026-03-01`, `lastSeen:-24h`.
     *
     * Ein Tag ohne Uhrzeit ist eine **Spanne** und kein Zeitpunkt, und genau so
     * wird er gelesen: `firstSeen:2026-03-01` meint diesen Tag, `<=` seinen
     * letzten Augenblick, `>` seinen ersten danach. Alles andere führt zu der
     * Enttäuschung, dass „bis zum 1. März" den 1. März nicht mehr enthält.
     */
    private function moment(Condition $condition, string $column): Closure
    {
        $value = $condition->value;

        // Eine Angabe wie `-24h` sagt die Richtung schon selbst; ein Vergleich
        // davor wäre eine zweite, womöglich gegenläufige Ansage.
        if (preg_match('/^([+-])(\d+)([mhdw])$/i', $value, $relative) === 1) {
            if ($condition->comparator !== Comparator::Equals) {
                throw new SearchSyntaxException(
                    __('search.errors.relative_with_comparison', ['field' => $condition->field]),
                    $condition->valuePosition,
                    $value,
                );
            }

            $moment = self::ago((int) $relative[2], mb_strtolower($relative[3]));
            $operator = $relative[1] === '-' ? '>=' : '<=';

            return fn (Builder $query) => $query->where($column, $operator, $moment);
        }

        [$from, $to] = $this->span($condition);

        return match ($condition->comparator) {
            Comparator::Equals => fn (Builder $query) => $query->whereBetween($column, [$from, $to]),
            Comparator::GreaterThan => fn (Builder $query) => $query->where($column, '>', $to),
            Comparator::GreaterOrEqual => fn (Builder $query) => $query->where($column, '>=', $from),
            Comparator::LessThan => fn (Builder $query) => $query->where($column, '<', $from),
            Comparator::LessOrEqual => fn (Builder $query) => $query->where($column, '<=', $to),
        };
    }

    /**
     * Die Spanne, die eine Datumsangabe meint — bei einer Uhrzeit ist sie eine
     * Sekunde lang, bei einem bloßen Tag ein Tag.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function span(Condition $condition): array
    {
        $value = $condition->value;

        $isDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        $isMoment = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $value) === 1;

        if (! $isDay && ! $isMoment) {
            throw new SearchSyntaxException(
                __('search.errors.not_a_date', [
                    'field' => $condition->field,
                    'value' => $value,
                ]),
                $condition->valuePosition,
                $value,
            );
        }

        try {
            $start = CarbonImmutable::parse($value, $this->timezone);
        } catch (Exception) {
            throw new SearchSyntaxException(
                __('search.errors.not_a_date', [
                    'field' => $condition->field,
                    'value' => $value,
                ]),
                $condition->valuePosition,
                $value,
            );
        }

        $end = $isDay ? $start->endOfDay() : $start->addSecond()->subMicrosecond();

        return [$start->utc(), $end->utc()];
    }

    /**
     * Der Zeitpunkt, der `$amount` Einheiten zurückliegt.
     */
    private static function ago(int $amount, string $unit): CarbonImmutable
    {
        $now = CarbonImmutable::instance(Date::now())->utc();

        return match ($unit) {
            'm' => $now->subMinutes($amount),
            'h' => $now->subHours($amount),
            'd' => $now->subDays($amount),
            default => $now->subWeeks($amount),
        };
    }

    /**
     * `release:` und `firstRelease:`.
     *
     * `release:` fragt die **erste oder letzte** bekannte Version ab — dieselbe
     * Auskunft mit derselben Grenze wie seit S1: erfasst sind genau diese
     * beiden, eine Version dazwischen kennt der Eintrag nicht.
     *
     * @param  list<string>  $relations
     */
    private function release(Condition $condition, array $relations): Closure
    {
        self::rejectComparator($condition);

        $match = self::matcher($condition, 'version');

        return function (Builder $query) use ($relations, $match): void {
            $query->where(function (Builder $any) use ($relations, $match): void {
                foreach ($relations as $relation) {
                    $any->orWhereHas($relation, $match);
                }
            });
        };
    }

    /**
     * Ein Merkmal — der Regelfall für alles ohne eigene Bedeutung.
     */
    private function tag(Condition $condition): Closure
    {
        self::rejectComparator($condition);

        $key = $condition->key();
        $match = self::matcher($condition, 'tag_value');
        $table = (new IssueTag)->getTable();

        // Existenzprüfung statt Verknüpfung: sie trifft den eindeutigen Index
        // (issue_id, tag_key, tag_value) und ist mit der ersten Zeile fertig.
        // Eine Verknüpfung würde stattdessen die Zähler-Spalten mitschleppen
        // und die Sortierung der Liste um eine Tabelle erweitern.
        return function (Builder $query) use ($table, $key, $match): void {
            $query->whereExists(function (QueryBuilder $sub) use ($table, $key, $match): void {
                $sub->select(1)
                    ->from($table)
                    ->whereColumn($table.'.issue_id', 'issues.id')
                    ->where($table.'.tag_key', $key);

                $match($sub);
            });
        };
    }

    /**
     * `user.email:` und Geschwister — die einzige Abfrage dieser Liste, die
     * **Ereignisse** liest.
     *
     * Sie ist deshalb so eng wie möglich gefasst: über die Gruppen des einen
     * Eintrags, zusätzlich auf dessen Projekt festgenagelt, und als
     * Existenzprüfung, die mit dem ersten Treffer aufhört. Ein Index auf der
     * Nutzer-Angabe gibt es nicht und kann es nicht geben — sie steht im
     * gespeicherten Datensatz, damit das Scrubbing (I7) und die Aufbewahrung
     * (O2) genau eine Stelle haben, an der sie wirken. Wer über eine Million
     * Ereignisse nach einer Adresse sucht, wartet; wer nach `browser:Chrome`
     * sucht, nicht.
     */
    private function user(string $field, Condition $condition): Closure
    {
        self::rejectComparator($condition);

        $column = 'events.user->'.$field;
        $wildcard = $condition->hasWildcard();
        $value = $wildcard ? self::pattern($condition->value) : $condition->value;

        return function (Builder $query) use ($column, $value, $wildcard): void {
            $query->whereExists(function (QueryBuilder $sub) use ($column, $value, $wildcard): void {
                $sub->select(1)
                    ->from('events')
                    ->join('event_groups', 'event_groups.id', '=', 'events.event_group_id')
                    ->whereColumn('event_groups.issue_id', 'issues.id')
                    ->whereColumn('events.project_id', 'issues.project_id');

                if ($wildcard) {
                    $sub->whereRaw(
                        $sub->getGrammar()->wrap($column).' like ? escape \''.self::ESCAPE.'\'',
                        [$value],
                    );

                    return;
                }

                $sub->where($column, $value);
            });
        };
    }

    /**
     * Der Vergleich eines Wertes — genau oder mit Platzhalter.
     *
     * @return Closure(Builder<*>|QueryBuilder): void
     */
    private static function matcher(Condition $condition, string $column): Closure
    {
        if (! $condition->hasWildcard()) {
            $value = $condition->value;

            return function (Builder|QueryBuilder $query) use ($column, $value): void {
                $query->where($column, $value);
            };
        }

        $pattern = self::pattern($condition->value);

        return function (Builder|QueryBuilder $query) use ($column, $pattern): void {
            $query->whereRaw($column.' like ? escape \''.self::ESCAPE.'\'', [$pattern]);
        };
    }

    /**
     * Aus `Chrome 12*` wird `Chrome 12%` — und aus einem echten Prozentzeichen
     * im Wert ein geflüchtetes, damit es keines wird.
     */
    private static function pattern(string $value): string
    {
        return str_replace('*', '%', self::escape($value));
    }

    /**
     * Macht die Platzhalter von `LIKE` zu gewöhnlichen Zeichen. Das
     * Fluchtzeichen selbst zuerst, sonst bricht die zweite Ersetzung die erste
     * wieder auf.
     */
    private static function escape(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $value,
        );
    }

    /**
     * Felder, bei denen `>` und `<` keinen Sinn ergeben, sagen das — statt den
     * Vergleich stillschweigend zum Teil des Wertes zu machen.
     */
    private static function rejectComparator(Condition $condition): void
    {
        if ($condition->comparator === Comparator::Equals) {
            return;
        }

        throw new SearchSyntaxException(
            __('search.errors.no_comparison', [
                'field' => $condition->field,
                'comparator' => $condition->comparator->value,
            ]),
            $condition->valuePosition,
            $condition->comparator->value.$condition->value,
        );
    }
}
