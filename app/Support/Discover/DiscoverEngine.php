<?php

namespace App\Support\Discover;

use App\Support\Search\SearchExpression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Der Auswertungs-Motor: eine Abfrage hinein, Zahlen heraus.
 *
 * **Ein Motor und nicht drei.** Die freien Auswertungen (D2), die Bausteine der
 * Übersichtsseiten (D4) und die Schwellwert-Alarme (A3) fragen dasselbe: eine
 * Kennzahl über einen Zeitraum, gefiltert, gruppiert, gegebenenfalls über die Zeit
 * aufgeteilt. Würde jede Oberfläche ihre eigene Rechnung mitbringen, unterschieden
 * sich Diagramm, Kachel und Alarm um Nuancen — die Kachel zählt Messungen, der
 * Alarm rechnet hoch, das Diagramm rundet anders —, und niemand könnte sagen,
 * welche der drei Zahlen stimmt. Deshalb ist dieser Motor die einzige Stelle, an der
 * aus Zeilen Kennzahlen werden.
 *
 * **Er weiß nichts über Tabellen.** Was ein Feld bedeutet und wie eine Kennzahl in
 * dieser Quelle gerechnet wird, steht in {@see DatasetFields}; was die Suchsprache
 * bedeutet, in S4. Hier steht nur, was für alle Quellen gleich ist: Zeitraum
 * abgrenzen, gruppieren, sortieren, begrenzen, rastern, Lücken füllen. Eine neue
 * Quelle ändert deshalb nichts an dieser Klasse.
 *
 * **Drei Dinge, die er zusagt.**
 *
 *   - **Grenzen vor der Datenbank.** Was sich an der Abfrage erkennen lässt, wird
 *     abgelehnt, bevor gefragt wird ({@see DiscoverLimits}); was nicht, überlässt er
 *     der Zeitgrenze der Datenbank. Beides mit Zahl und Grenze in der Meldung.
 *   - **Nichts wird stillschweigend gekürzt.** Mehr Gruppen als angefordert stehen
 *     als Vermerk am Ergebnis.
 *   - **Tabelle und Reihe derselben Frage stimmen überein.** Die Linien einer Reihe
 *     sind die Zeilen der Tabelle; die Summe einer Linie ist die Zahl in der Zeile.
 */
final class DiscoverEngine
{
    private readonly DiscoverLimits $limits;

    private readonly DiscoverCache $cache;

    public function __construct(?DiscoverLimits $limits = null, ?DiscoverCache $cache = null)
    {
        $this->limits = $limits ?? DiscoverLimits::fromConfig();
        $this->cache = $cache ?? new DiscoverCache($this->limits->cacheTtl);
    }

    /**
     * Die Auswertung als Tabelle: eine Zeile je Gruppe, oder genau eine Zeile ohne
     * Gruppierung.
     */
    public function table(DiscoverQuery $query): DiscoverResult
    {
        $this->limits->check($query);

        $cached = $this->cache->table($query);

        if ($cached !== null) {
            return $cached->withCached(true);
        }

        $result = $this->runTable($query);

        $this->cache->store($query, 'table', $result);

        return $result;
    }

    /**
     * Dieselbe Auswertung über die Zeit aufgeteilt.
     */
    public function series(DiscoverQuery $query): DiscoverSeries
    {
        if ($query->interval === null) {
            throw DiscoverException::invalid('Eine Zeitreihe braucht eine Schrittweite.');
        }

        $this->limits->check($query);

        $cached = $this->cache->series($query);

        if ($cached !== null) {
            return $cached->withCached(true);
        }

        $series = $this->runSeries($query, $query->interval);

        $this->cache->store($query, 'series', $series);

        return $series;
    }

    private function runTable(DiscoverQuery $query): DiscoverResult
    {
        $fields = $query->dataset->fields($query->timezone);
        $search = SearchExpression::compile($query->search, $fields);

        $groups = $this->groupExpressions($query, $fields);
        $measures = $this->measures($query, $fields);
        $order = $this->ordering($query, $groups, $measures);

        $statement = $this->base($query, $fields, $search);

        $this->select($statement, $fields->connection(), array_merge(
            $this->groupSelects($groups),
            $this->measureSelects($measures),
        ));

        if ($groups !== []) {
            $statement->groupByRaw(implode(', ', array_keys($groups)));

            if ($order['sql'] !== null) {
                $statement->orderByRaw($order['sql'].' '.$order['direction']);
            }

            // Muss selbst sortiert werden (ein Perzentil entsteht erst in PHP),
            // dann werden so viele Gruppen geholt, wie erlaubt sind — und dass es
            // dafür mehr gab, steht hinterher am Ergebnis. Eine Rangliste aus den
            // ersten fünfzig beliebigen Gruppen wäre keine.
            $statement->limit($order['sql'] === null ? $this->limits->maxGroups : $query->limit + 1);
        }

        $rows = $this->fetch($statement);
        $mapped = $this->mapRows($rows, $query->groupBy, $measures);

        if ($groups === []) {
            // Ohne Gruppierung gibt es die eine Zeile immer — auch wenn keine
            // Daten vorliegen. `count()` ist dann null und keine leere Antwort.
            $mapped = $mapped === [] ? [$this->mapRow([], $query->groupBy, $measures)] : [$mapped[0]];

            return new DiscoverResult($mapped, [], array_keys($measures), false, $fields->unavailable(), $search->error?->toArray());
        }

        if ($order['sql'] === null) {
            $mapped = $this->sortRows($mapped, $order['key'], $order['descending']);
            $truncated = count($rows) >= $this->limits->maxGroups;
        } else {
            $truncated = count($mapped) > $query->limit;
        }

        return new DiscoverResult(
            array_slice($mapped, 0, $query->limit),
            $query->groupBy,
            array_keys($measures),
            $truncated,
            $fields->unavailable(),
            $search->error?->toArray(),
        );
    }

    private function runSeries(DiscoverQuery $query, Interval $interval): DiscoverSeries
    {
        $fields = $query->dataset->fields($query->timezone);
        $search = SearchExpression::compile($query->search, $fields);

        $groups = $this->groupExpressions($query, $fields);
        $measures = $this->measures($query, $fields);
        $aliases = array_keys($measures);

        // Die Linien sind die Zeilen der Tabelle und werden nicht neu bestimmt:
        // nur so ist die Summe einer Linie die Zahl, die in der Tabelle steht.
        $lines = [];
        $truncated = false;

        if ($groups !== []) {
            $top = $this->runTable($query->limitedTo(min($query->limit, $this->limits->maxSeriesGroups)));
            $lines = $top->rows;
            $truncated = $top->truncated;

            if ($lines === []) {
                return new DiscoverSeries([], $interval, $query->groupBy, $aliases, false, $fields->unavailable(), $search->error?->toArray());
            }
        }

        $statement = $this->base($query, $fields, $search);

        if ($lines !== []) {
            $this->restrictToLines($statement, $groups, $query->groupBy, $lines);
        }

        [$bucket, $bindings] = Sql::bucketIndex(
            $fields->connection(),
            $fields->timeColumn(),
            $interval,
            $query->range,
        );

        $this->select($statement, $fields->connection(), array_merge(
            [$bucket.' as bucket'],
            $this->groupSelects($groups),
            $this->measureSelects($measures),
        ), $bindings);

        $statement->groupByRaw(implode(', ', array_merge(['bucket'], array_keys($groups))));

        $rows = $this->fetch($statement);
        $points = $interval->points($query->range);
        $buckets = $interval->buckets($query->range);

        /** @var array<string, array<int, array<string, float|null>>> $collected */
        $collected = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $index = (int) ($values['bucket'] ?? -1);

            if ($index < 0 || $index >= $points) {
                continue;
            }

            $mapped = $this->mapRow($values, $query->groupBy, $measures);
            $collected[$this->lineKey($mapped->groups, $query->groupBy)][$index] = $mapped->values;
        }

        if ($lines === []) {
            $lines = [new DiscoverRow([], [])];
        }

        $series = [];

        foreach ($lines as $line) {
            $found = $collected[$this->lineKey($line->groups, $query->groupBy)] ?? [];
            $linePoints = [];

            foreach ($buckets as $index => $at) {
                $linePoints[] = new SeriesPoint($at, $found[$index] ?? $this->emptyValues($query));
            }

            $series[] = new SeriesGroup($line->groups, $linePoints);
        }

        return new DiscoverSeries(
            $series,
            $interval,
            $query->groupBy,
            $aliases,
            $truncated,
            $fields->unavailable(),
            $search->error?->toArray(),
        );
    }

    /**
     * Die Abfrage, auf der beide Formen aufsetzen: ein Projekt, ein Zeitraum, die
     * Suchbedingung.
     *
     * Der Zeitraum ist **oben offen**, wie das Fenster der Alarme: sonst zählt die
     * Messung auf der Grenze in zwei aufeinanderfolgenden Abschnitten mit, und die
     * Summe der Stunden ist größer als der Tag.
     *
     * @return Builder<*>
     */
    private function base(DiscoverQuery $query, DatasetFields $fields, SearchExpression $search): Builder
    {
        $time = $fields->timeColumn();
        $table = str_contains($time, '.') ? explode('.', $time)[0].'.' : '';

        $statement = $fields->query()
            ->where($table.'project_id', $query->projectId)
            ->where($time, '>=', $query->range->from)
            ->where($time, '<', $query->range->to);

        $search->apply($statement);

        return $statement;
    }

    /**
     * Die Gruppierung: Alias auf SQL-Ausdruck.
     *
     * Die Aliasse sind gezählt (`group_0`) und nicht die Feldnamen: ein Merkmal darf
     * Punkte und Klammern im Namen haben (`tags[checkout_step]`), ein Spaltenname
     * nicht.
     *
     * @return array<string, string>
     */
    private function groupExpressions(DiscoverQuery $query, DatasetFields $fields): array
    {
        $groups = [];

        foreach ($query->groupBy as $index => $name) {
            $definition = $fields->definition($name);

            if ($definition === null || ! $definition->groupable) {
                throw DiscoverException::unknownField($fields->dataset(), $name);
            }

            $groups['group_'.$index] = $definition->sql;
        }

        return $groups;
    }

    /**
     * @return array<string, Measure>
     */
    private function measures(DiscoverQuery $query, DatasetFields $fields): array
    {
        $measures = [];

        foreach ($query->aggregations as $aggregation) {
            $measures[$aggregation->alias()] = $fields->measure($aggregation);
        }

        return $measures;
    }

    /**
     * @param  array<string, string>  $groups
     * @return list<string>
     */
    private function groupSelects(array $groups): array
    {
        $selects = [];

        foreach ($groups as $alias => $sql) {
            $selects[] = $sql.' as '.$alias;
        }

        return $selects;
    }

    /**
     * Die Auswahl-Ausdrücke aller Kennzahlen, ohne Doppelungen.
     *
     * Zwei Perzentile über dasselbe Feld brauchen dieselben einunddreißig
     * Klassensummen. Ohne diese Zusammenlegung stünden sie zweimal in der Abfrage —
     * mit demselben Alias, was keine Datenbank annimmt.
     *
     * @param  array<string, Measure>  $measures
     * @return list<string>
     */
    private function measureSelects(array $measures): array
    {
        $selects = [];
        $seen = [];

        foreach ($measures as $measure) {
            foreach ($measure->selects as $select) {
                $position = strripos($select, ' as ');
                $alias = $position === false ? $select : trim(mb_substr($select, $position + 4));

                if (isset($seen[$alias])) {
                    continue;
                }

                $seen[$alias] = true;
                $selects[] = $select;
            }
        }

        return $selects;
    }

    /**
     * Wonach sortiert wird — und ob die Datenbank es kann.
     *
     * @param  array<string, string>  $groups
     * @param  array<string, Measure>  $measures
     * @return array{key: string, descending: bool, direction: string, sql: string|null}
     */
    private function ordering(DiscoverQuery $query, array $groups, array $measures): array
    {
        $ordering = $query->orderBy ?? Ordering::desc((string) array_key_first($measures));
        $key = $ordering->key;

        $sql = null;

        if (isset($measures[$key])) {
            $sql = $measures[$key]->order;
        } else {
            $index = array_search($key, $query->groupBy, true);

            if ($index === false) {
                throw DiscoverException::invalid('Nach „'.$key.'" lässt sich nicht sortieren: weder Kennzahl noch Gruppierung.');
            }

            $sql = $groups['group_'.$index];
        }

        return [
            'key' => $key,
            'descending' => $ordering->descending,
            'direction' => $ordering->direction(),
            'sql' => $sql,
        ];
    }

    /**
     * Legt die Auswahl auf die Abfrage — mit der Zeitgrenze davor, die MySQL selbst
     * einhält.
     *
     * @param  Builder<*>  $statement
     * @param  list<string>  $selects
     * @param  list<string>  $bindings
     */
    private function select(Builder $statement, Connection $connection, array $selects, array $bindings = []): void
    {
        $hint = Sql::timeout($connection, $this->limits->timeoutMs);

        $statement->selectRaw($hint.implode(', ', $selects), $bindings);
    }

    /**
     * Schränkt die Reihe auf die Gruppen der Tabelle ein.
     *
     * Ein fehlender Merkmalswert ist dabei eine Gruppe wie jede andere: `null` ist
     * die Aussage „dieses Merkmal hat die Zeile nicht" und muss in der Reihe
     * denselben Balken ergeben wie in der Tabelle.
     *
     * @param  Builder<*>  $statement
     * @param  array<string, string>  $groups
     * @param  list<string>  $groupBy
     * @param  list<DiscoverRow>  $lines
     */
    private function restrictToLines(Builder $statement, array $groups, array $groupBy, array $lines): void
    {
        $statement->where(function (Builder $outer) use ($groups, $groupBy, $lines): void {
            foreach ($lines as $line) {
                $outer->orWhere(function (Builder $inner) use ($groups, $groupBy, $line): void {
                    foreach ($groupBy as $index => $name) {
                        $sql = $groups['group_'.$index];
                        $value = $line->groups[$name] ?? null;

                        if ($value === null) {
                            $inner->whereRaw($sql.' is null');

                            continue;
                        }

                        $inner->whereRaw($sql.' = ?', [$value]);
                    }
                });
            }
        });
    }

    /**
     * @param  list<object>  $rows
     * @param  list<string>  $groupBy
     * @param  array<string, Measure>  $measures
     * @return list<DiscoverRow>
     */
    private function mapRows(array $rows, array $groupBy, array $measures): array
    {
        return array_map(
            fn (object $row): DiscoverRow => $this->mapRow((array) $row, $groupBy, $measures),
            $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $groupBy
     * @param  array<string, Measure>  $measures
     */
    private function mapRow(array $row, array $groupBy, array $measures): DiscoverRow
    {
        $groups = [];

        foreach ($groupBy as $index => $name) {
            $value = $row['group_'.$index] ?? null;

            $groups[$name] = $value === null ? null : (string) $value;
        }

        $values = [];

        foreach ($measures as $alias => $measure) {
            $values[$alias] = ($measure->read)($row);
        }

        return new DiscoverRow($groups, $values);
    }

    /**
     * Sortiert selbst — der Fall, in dem die Kennzahl erst in PHP entsteht.
     *
     * `null` steht immer am Ende, in beiden Richtungen: „keine Antwortzeit gemessen"
     * ist nicht der kleinste Wert, sondern kein Wert.
     *
     * @param  list<DiscoverRow>  $rows
     * @return list<DiscoverRow>
     */
    private function sortRows(array $rows, string $key, bool $descending): array
    {
        usort($rows, static function (DiscoverRow $first, DiscoverRow $second) use ($key, $descending): int {
            $left = $first->value($key);
            $right = $second->value($key);

            if ($left === null || $right === null) {
                return ($left === null ? 1 : 0) <=> ($right === null ? 1 : 0);
            }

            return $descending ? $right <=> $left : $left <=> $right;
        });

        return $rows;
    }

    /**
     * Die Werte einer Stützstelle, für die es keine Zeile gab.
     *
     * Eine Anzahl ist null, alles andere unbekannt — dieselbe Unterscheidung, an der
     * bei den Alarmen hängt, ob ein stilles Zeitfenster Entwarnung gibt.
     *
     * @return array<string, float|null>
     */
    private function emptyValues(DiscoverQuery $query): array
    {
        $values = [];

        foreach ($query->aggregations as $aggregation) {
            $values[$aggregation->alias()] = $aggregation->aggregate->isCount() ? 0.0 : null;
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $groups
     * @param  list<string>  $groupBy
     */
    private function lineKey(array $groups, array $groupBy): string
    {
        $parts = [];

        foreach ($groupBy as $name) {
            $value = $groups[$name] ?? null;

            // Ein fehlender Wert und die Zeichenkette „" sind zwei Gruppen. Der
            // Marker hält sie auseinander, wo ein Schlüssel sie zusammenwirft.
            $parts[] = $value === null ? "\0null" : "\0=".$value;
        }

        return implode('', $parts);
    }

    /**
     * Führt die Abfrage aus — und übersetzt einen Abbruch der Datenbank in eine
     * Auskunft.
     *
     * @param  Builder<*>  $statement
     * @return list<object>
     */
    private function fetch(Builder $statement): array
    {
        try {
            return array_values($statement->toBase()->get()->all());
        } catch (QueryException $error) {
            throw $this->translate($error);
        }
    }

    private function translate(Throwable $error): Throwable
    {
        return Sql::isTimeout($error)
            ? DiscoverException::timeout($this->limits->timeoutMs)
            : $error;
    }
}
