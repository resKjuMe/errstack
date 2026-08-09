<?php

namespace App\Support\Discover\Datasets;

use App\Models\Transaction;
use App\Support\Discover\Aggregate;
use App\Support\Discover\Aggregation;
use App\Support\Discover\DatasetFields;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\FieldDefinition;
use App\Support\Discover\FieldType;
use App\Support\Discover\Measure;
use App\Support\Performance\DurationHistogram;
use App\Support\Search\Ast\Condition;
use App\Support\Search\Ast\FreeText;
use App\Support\Search\Comparator;
use App\Support\Search\SearchSyntaxException;
use Carbon\CarbonImmutable;
use Closure;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Was allen Datenquellen gemeinsam ist: die Übersetzung der Suchsprache und die
 * Rechenarten über einzelne Zeilen.
 *
 * Hier steht die Arbeit **einmal**, die sonst in jeder Quelle stünde: dass ein
 * Stern ein `like` wird, dass `>` nur bei Zahlen und Zeitpunkten etwas bedeutet,
 * dass ein Datum ohne Uhrzeit den Tag des Betrachters meint, und dass ein
 * Perzentil aus der Verteilung kommt. Eine Quelle beschreibt darüber nur noch ihre
 * Felder — und was sie anders macht als die anderen.
 *
 * Die vorberechneten Fenster ({@see TransactionWindowFields}) sind genau dieser
 * Fall: dort ist eine „Anzahl" eine Summe und kein Zählen, und deshalb überschreibt
 * die Klasse {@see self::measure()}. Der Rest — Suche, Gruppierung, Perzentile —
 * bleibt derselbe.
 */
abstract class AbstractDatasetFields implements DatasetFields
{
    /**
     * Fluchtzeichen für die Platzhalter von `like` — aus denselben Gründen kein
     * Rückstrich wie in der Fehlerliste: MySQL deutet ihn zweimal um, SQLite kennt
     * ihn dort gar nicht.
     */
    protected const ESCAPE = '!';

    /** @var array<string, FieldDefinition>|null */
    private ?array $fields = null;

    /** @var list<string> */
    private array $unavailable = [];

    /**
     * @param  string  $timezone  Zeitzone des Betrachters — ein Datum ohne
     *                            Uhrzeit meint seinen Tag und nicht den in UTC.
     */
    public function __construct(
        protected readonly string $timezone = 'UTC',
    ) {}

    /**
     * Die Felder dieser Quelle, nach Namen.
     *
     * @return array<string, FieldDefinition>
     */
    abstract protected function definitions(): array;

    /**
     * Die Spalte, in der die frei gesetzten Merkmale liegen — `null`, wenn die
     * Quelle keine hat.
     *
     * Hat sie eine, ist ein **unbekanntes Feld kein Fehler**: es ist ein Merkmal.
     * Das ist die Zusage, auf der die Suchsprache beruht — `checkout_step:3` soll
     * ohne Anmeldung eines Feldes funktionieren.
     */
    protected function tagColumn(): ?string
    {
        return null;
    }

    /**
     * Wo ein Begriff ohne Feld gesucht wird.
     *
     * @return list<string>
     */
    protected function freeTextColumns(): array
    {
        return [];
    }

    /**
     * Das Feld, auf das sich `apdex()` und die Antwortzeit beziehen.
     */
    protected function durationField(): ?string
    {
        return null;
    }

    /**
     * Der SQL-Ausdruck, der eine gescheiterte Zeile erkennt — für `failure_rate()`.
     */
    protected function failureExpression(): ?string
    {
        return null;
    }

    public function definition(string $name): ?FieldDefinition
    {
        $this->fields ??= $this->definitions();

        $key = mb_strtolower($name);

        if (isset($this->fields[$key])) {
            return $this->fields[$key];
        }

        return $this->tagDefinition($name);
    }

    public function groupable(): array
    {
        $this->fields ??= $this->definitions();

        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->name,
            array_filter($this->fields, static fn (FieldDefinition $field): bool => $field->groupable),
        ));
    }

    public function aggregatable(): array
    {
        $this->fields ??= $this->definitions();

        return array_values(array_map(
            static fn (FieldDefinition $field): string => $field->name,
            array_filter($this->fields, static fn (FieldDefinition $field): bool => $field->aggregatable),
        ));
    }

    public function unavailable(): array
    {
        return array_values(array_unique($this->unavailable));
    }

    public function condition(Condition $condition): ?Closure
    {
        $field = $this->definition($condition->field);

        if ($field === null) {
            // Kein Feld und kein Merkmal: die Bedingung schränkt nichts ein und
            // wird benannt. Eine Auswertung, die so tut, als hätte sie ein
            // unbekanntes Feld ausgewertet, ist schlimmer als eine, die sagt,
            // dass sie es nicht konnte.
            $this->unavailable[] = $condition->field;

            return null;
        }

        return match ($field->type) {
            FieldType::Text => $this->textCondition($field, $condition),
            FieldType::Number, FieldType::Duration => $this->numberCondition($field, $condition),
            FieldType::Timestamp => $this->timestampCondition($field, $condition),
        };
    }

    public function freeText(FreeText $text): ?Closure
    {
        $columns = $this->freeTextColumns();

        if ($columns === []) {
            return null;
        }

        $pattern = '%'.self::escape($text->text).'%';
        $columns = array_map(fn (string $column): string => $this->wrap($column), $columns);

        return function (Builder $query) use ($columns, $pattern): void {
            $query->where(function (Builder $group) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $group->orWhereRaw($column.' like ? escape \''.self::ESCAPE.'\'', [$pattern]);
                }
            });
        };
    }

    public function measure(Aggregation $aggregation): Measure
    {
        $alias = $aggregation->alias();

        if ($aggregation->aggregate === Aggregate::Count) {
            return Measure::scalar('count(*)', $alias, integer: true);
        }

        if ($aggregation->aggregate === Aggregate::Apdex) {
            return $this->apdexMeasure($alias);
        }

        if ($aggregation->aggregate === Aggregate::FailureRate) {
            return $this->failureRateMeasure($alias);
        }

        $field = $this->numericField($aggregation);

        if ($aggregation->aggregate === Aggregate::CountUnique) {
            return Measure::scalar('count(distinct '.$field->sql.')', $alias, integer: true);
        }

        $percentile = $aggregation->aggregate->percentile();

        if ($percentile !== null) {
            return $this->percentileMeasure($field, $percentile);
        }

        return Measure::scalar($aggregation->aggregate->value.'('.$field->sql.')', $alias);
    }

    /**
     * Das Feld einer Rechenart — und die Prüfung, dass sie darauf einen Sinn hat.
     */
    protected function numericField(Aggregation $aggregation): FieldDefinition
    {
        $name = (string) $aggregation->field;
        $field = $this->definition($name);

        if ($field === null || ! $field->aggregatable) {
            throw DiscoverException::unknownField($this->dataset(), $name);
        }

        if ($aggregation->aggregate === Aggregate::CountUnique) {
            return $field;
        }

        if (! $field->type->isNumeric()) {
            throw DiscoverException::unsupported(
                $this->dataset(),
                $aggregation->aggregate->value.' über das Feld '.$name,
            );
        }

        if ($aggregation->aggregate->percentile() !== null && $field->type !== FieldType::Duration) {
            throw DiscoverException::unsupported(
                $this->dataset(),
                'ein Perzentil über das Feld '.$name,
            );
        }

        return $field;
    }

    /**
     * Ein Perzentil über die Klassen der Verteilung.
     *
     * Einunddreißig Summen und eine Rechnung in PHP — dieselbe, mit der die
     * Antwortzeit-Übersicht (PF2) und die Alarme (A3) ihre Perzentile lesen. Die
     * Klassengrenzen kommen aus derselben Klasse, damit eine Änderung an der
     * Auflösung nicht an zwei Stellen nachgezogen werden muss.
     */
    protected function percentileMeasure(FieldDefinition $field, float $percentile): Measure
    {
        // Der Vorsatz trägt den Feldnamen, damit zwei Perzentile über **zwei**
        // Felder in einer Abfrage nicht dieselben Aliasse belegen: sie würden
        // zusammengelegt, und das zweite Perzentil läse still die Verteilung des
        // ersten. Gleiche Felder dürfen dagegen zusammenfallen — ein p50 und ein p95
        // über dieselbe Dauer brauchen dieselben Klassensummen nur einmal.
        $prefix = self::bucketPrefix($field->name);

        return new Measure(
            DurationHistogram::countExpressions($field->sql, $prefix),
            static function (array $row) use ($percentile, $prefix): ?float {
                $value = DurationHistogram::fromRowSums($row, $prefix)->percentile($percentile);

                return $value === null ? null : (float) $value;
            },
        );
    }

    /**
     * Der Namensvorsatz der Klassensummen eines Feldes — ein Alias und deshalb ohne
     * Punkte und Klammern.
     */
    protected static function bucketPrefix(string $field): string
    {
        return 'bucket_'.trim(mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $field)), '_');
    }

    /**
     * Die Zufriedenheit mit den Antwortzeiten — zufrieden ganz, geduldig zur
     * Hälfte, der Rest nicht.
     *
     * Die beiden Schwellen stehen in der Konfiguration und werden hier gelesen und
     * nicht nachgebaut ({@see Transaction::miserable()}): eine
     * Kennzahl, die in der Übersicht und in der freien Auswertung verschieden
     * gerechnet wird, ist zwei Kennzahlen mit einem Namen.
     */
    protected function apdexMeasure(string $alias): Measure
    {
        $name = $this->durationField();

        if ($name === null) {
            throw DiscoverException::unsupported($this->dataset(), 'apdex');
        }

        $field = $this->definition($name);

        if ($field === null) {
            throw DiscoverException::unknownField($this->dataset(), $name);
        }

        $threshold = (int) config('ingest.performance.apdex_threshold_us');
        $factor = (int) config('ingest.performance.misery_factor');

        if ($threshold < 1 || $factor < 1) {
            throw DiscoverException::unsupported($this->dataset(), 'apdex ohne eingestellte Schwelle');
        }

        $satisfied = sprintf('sum(case when %s <= %d then 1 else 0 end)', $field->sql, $threshold);
        $tolerating = sprintf(
            'sum(case when %1$s > %2$d and %1$s <= %3$d then 1 else 0 end)',
            $field->sql,
            $threshold,
            $threshold * $factor,
        );
        $expression = sprintf('(%s + %s / 2.0) / count(*)', $satisfied, $tolerating);

        return new Measure(
            [$satisfied.' as '.$alias.'_satisfied', $tolerating.' as '.$alias.'_tolerating', 'count(*) as '.$alias.'_total'],
            static function (array $row) use ($alias): ?float {
                $total = (int) ($row[$alias.'_total'] ?? 0);

                if ($total === 0) {
                    return null;
                }

                return ((int) ($row[$alias.'_satisfied'] ?? 0) + (int) ($row[$alias.'_tolerating'] ?? 0) / 2) / $total;
            },
            $expression,
        );
    }

    /**
     * Der Anteil der gescheiterten Zeilen, in Prozent.
     *
     * Keine Zeilen heißt **nicht** null Prozent: aus nichts folgt keine Quote.
     * Genau daran hängt bei den Alarmen, ob ein stilles Zeitfenster Entwarnung
     * gibt oder den Zustand hält.
     */
    protected function failureRateMeasure(string $alias): Measure
    {
        $failure = $this->failureExpression();

        if ($failure === null) {
            throw DiscoverException::unsupported($this->dataset(), 'failure_rate');
        }

        $failures = sprintf('sum(case when %s then 1 else 0 end)', $failure);
        $expression = sprintf('%s * 100.0 / count(*)', $failures);

        return new Measure(
            [$failures.' as '.$alias.'_failures', 'count(*) as '.$alias.'_total'],
            static function (array $row) use ($alias): ?float {
                $total = (int) ($row[$alias.'_total'] ?? 0);

                if ($total === 0) {
                    return null;
                }

                return (int) ($row[$alias.'_failures'] ?? 0) / $total * 100;
            },
            $expression,
        );
    }

    /**
     * Ein Merkmal, das nicht in der Feldliste steht — `tags[checkout_step]` oder
     * schlicht `checkout_step`.
     */
    private function tagDefinition(string $name): ?FieldDefinition
    {
        $column = $this->tagColumn();

        if ($column === null) {
            return null;
        }

        $key = $name;

        if (preg_match('/^tags\[(.+)\]$/', $name, $matches) === 1) {
            $key = $matches[1];
        }

        // Was in einem JSON-Pfad steht, muss ein Feldname sein können: ein
        // Anführungszeichen oder eine Klammer darin wäre ein Ausdruck und keine
        // Angabe mehr.
        if (preg_match('/^[A-Za-z0-9_.\-]{1,200}$/', $key) !== 1) {
            return null;
        }

        $path = $column.'->'.$key;

        return new FieldDefinition($name, FieldType::Text, $path, $this->wrap($path), aggregatable: true);
    }

    /**
     * @return Closure(Builder<*>): void
     */
    private function textCondition(FieldDefinition $field, Condition $condition): Closure
    {
        if ($condition->comparator !== Comparator::Equals) {
            throw new SearchSyntaxException(
                'Ein Vergleich ergibt bei „'.$condition->field.'" keinen Sinn.',
                $condition->position,
                $condition->field,
            );
        }

        $sql = $field->sql;
        $column = $field->column;

        if ($condition->hasWildcard()) {
            $pattern = str_replace('*', '%', self::escape($condition->value));

            return function (Builder $query) use ($sql, $pattern): void {
                $query->whereRaw($sql.' like ? escape \''.self::ESCAPE.'\'', [$pattern]);
            };
        }

        $value = $condition->value;

        if ($column !== null) {
            return function (Builder $query) use ($column, $value): void {
                $query->where($column, $value);
            };
        }

        return function (Builder $query) use ($sql, $value): void {
            $query->whereRaw($sql.' = ?', [$value]);
        };
    }

    /**
     * @return Closure(Builder<*>): void
     */
    private function numberCondition(FieldDefinition $field, Condition $condition): Closure
    {
        if (! is_numeric($condition->value)) {
            throw new SearchSyntaxException(
                'Bei „'.$condition->field.'" wird eine Zahl erwartet.',
                $condition->valuePosition,
                $condition->value,
            );
        }

        $sql = $field->sql;
        $operator = $condition->comparator->sql();
        $value = (float) $condition->value;

        return function (Builder $query) use ($sql, $operator, $value): void {
            $query->whereRaw($sql.' '.$operator.' ?', [$value]);
        };
    }

    /**
     * @return Closure(Builder<*>): void
     */
    private function timestampCondition(FieldDefinition $field, Condition $condition): Closure
    {
        $value = trim($condition->value);
        $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        try {
            $at = CarbonImmutable::parse($value, $this->timezone);
        } catch (Exception) {
            throw new SearchSyntaxException(
                'Bei „'.$condition->field.'" wird ein Zeitpunkt erwartet.',
                $condition->valuePosition,
                $condition->value,
            );
        }

        $sql = $field->sql;

        // Ein Tag ohne Uhrzeit ist ein Zeitraum und kein Zeitpunkt: `=` meint
        // „an diesem Tag", `<` meint „vor diesem Tag" und `>` „nach seinem Ende".
        if ($dateOnly && $condition->comparator === Comparator::Equals) {
            $from = $at->startOfDay()->utc();
            $to = $at->addDay()->startOfDay()->utc();

            return function (Builder $query) use ($sql, $from, $to): void {
                $query->whereRaw($sql.' >= ? and '.$sql.' < ?', [self::stamp($from), self::stamp($to)]);
            };
        }

        $boundary = match (true) {
            $dateOnly && $condition->comparator === Comparator::GreaterThan => $at->addDay()->startOfDay(),
            $dateOnly && $condition->comparator === Comparator::LessOrEqual => $at->addDay()->startOfDay(),
            $dateOnly => $at->startOfDay(),
            default => $at,
        };

        // `<=` auf einen ganzen Tag heißt „bis zu seinem Ende" — und das ist die
        // Grenze des nächsten Tages, oben offen.
        $operator = $dateOnly && $condition->comparator === Comparator::LessOrEqual
            ? Comparator::LessThan->sql()
            : $condition->comparator->sql();

        $stamp = self::stamp($boundary->utc());

        return function (Builder $query) use ($sql, $operator, $stamp): void {
            $query->whereRaw($sql.' '.$operator.' ?', [$stamp]);
        };
    }

    /**
     * Ein Textfeld — der Regelfall.
     */
    protected function text(string $name, string $column, bool $groupable = true): FieldDefinition
    {
        return new FieldDefinition($name, FieldType::Text, $column, $this->wrap($column), $groupable, aggregatable: true);
    }

    /**
     * Ein Zahlenfeld, über das sich rechnen lässt.
     */
    protected function number(string $name, string $column, FieldType $type = FieldType::Number): FieldDefinition
    {
        return new FieldDefinition($name, $type, $column, $this->wrap($column), groupable: false, aggregatable: true);
    }

    /**
     * Ein Zeitpunkt: filterbar, aber nichts, wonach sich gruppieren lässt — dafür
     * ist die Zeitreihe da.
     */
    protected function timestamp(string $name, string $column): FieldDefinition
    {
        return new FieldDefinition($name, FieldType::Timestamp, $column, $this->wrap($column), groupable: false);
    }

    /**
     * Die Feldliste nach Namen — die Form, in der {@see self::definition()} sie
     * erwartet.
     *
     * @param  list<FieldDefinition>  $fields
     * @return array<string, FieldDefinition>
     */
    protected function keyed(array $fields): array
    {
        $keyed = [];

        foreach ($fields as $field) {
            $keyed[mb_strtolower($field->name)] = $field;
        }

        return $keyed;
    }

    /**
     * Ein Pfad, wie der Treiber ihn schreibt — bei einer JSON-Spalte also mit
     * seinem eigenen Zugriff darauf.
     */
    protected function wrap(string $column): string
    {
        return $this->connection()->getQueryGrammar()->wrap($column);
    }

    public function connection(): Connection
    {
        return $this->query()->getModel()->getConnection();
    }

    private static function stamp(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    /**
     * Macht aus einem Wert einen, den `like` wörtlich nimmt.
     */
    protected static function escape(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $value,
        );
    }
}
