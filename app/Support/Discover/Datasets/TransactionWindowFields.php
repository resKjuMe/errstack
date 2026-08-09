<?php

namespace App\Support\Discover\Datasets;

use App\Models\TransactionAggregate;
use App\Support\Discover\Aggregate;
use App\Support\Discover\Aggregation;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\FieldDefinition;
use App\Support\Discover\FieldType;
use App\Support\Discover\Measure;
use App\Support\Performance\DurationHistogram;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Felder der vorberechneten Minuten-Fenster der Antwortzeiten (PF1).
 *
 * **Eine Zeile ist hier keine Messung, sondern eine Minute** — und daran hängt
 * alles, was diese Quelle von den Einzelmessungen unterscheidet. `count()` ist
 * deshalb eine **Summe** über die Zähler der Fenster und kein `count(*)`: das
 * würde Minuten zählen. Ebenso ist der Mittelwert der Quotient zweier Summen und
 * nicht `avg()` über eine Spalte, und das Perzentil kommt aus den summierten
 * Klassen der abgelegten Verteilung statt aus einer Wertespalte.
 *
 * Das ist der Grund, warum diese Klasse {@see self::measure()} überschreibt und
 * nicht bloß eine andere Feldliste mitbringt: die Rechenarten heißen gleich,
 * meinen dasselbe und werden anders gerechnet. Wer das nicht trennt, bekommt eine
 * Zahl, die je nach Quelle um den Faktor „Messungen je Minute" verschieden ist.
 *
 * **Wozu es die Quelle gibt:** ein p95 je Seite über dreißig Tage ist hier eine
 * Handvoll Zeilen und aus den Einzelmessungen ein Durchlauf. Die Alarme (A3) lesen
 * jede Minute genau eine solche Zeile; die Bausteine (D4) werden es für ihre
 * Kacheln genauso tun.
 *
 * **Was hier fehlt, fehlt in den Daten**: eine Nutzer-, Browser- oder
 * Adress-Dimension trägt ein Fenster nicht (das wäre der Weg zurück zu einer Zeile
 * je Messung). Wer danach gruppieren will, nimmt {@see TransactionFields}.
 */
final class TransactionWindowFields extends AbstractDatasetFields
{
    public function dataset(): Dataset
    {
        return Dataset::TransactionWindows;
    }

    public function query(): Builder
    {
        return TransactionAggregate::query();
    }

    public function timeColumn(): string
    {
        return 'transaction_aggregates.window_start';
    }

    protected function freeTextColumns(): array
    {
        return ['transaction_aggregates.name'];
    }

    /**
     * @return array<string, FieldDefinition>
     */
    protected function definitions(): array
    {
        return $this->keyed([
            $this->text('name', 'transaction_aggregates.name'),
            $this->text('op', 'transaction_aggregates.op'),
            $this->text('environment', 'transaction_aggregates.environment'),
            $this->number('duration', 'transaction_aggregates.duration_sum_us', FieldType::Duration),
            $this->number('throughput', 'transaction_aggregates.extrapolated_count'),
            $this->timestamp('window_start', 'transaction_aggregates.window_start'),
        ]);
    }

    public function measure(Aggregation $aggregation): Measure
    {
        $counted = $this->wrap('transaction_aggregates.transaction_count');
        $alias = $aggregation->alias();

        // Die gemessenen Aufrufe — der Nenner jeder Quote und jedes Mittelwerts.
        // Nicht die hochgerechnete Zahl: bei aktiver Stichprobe (I9) stünde sonst
        // eine Quote aus hochgerechneten Zählern über gemessenen Fehlern.
        $measured = 'sum('.$counted.')';

        return match ($aggregation->aggregate) {
            Aggregate::Count => Measure::scalar($measured, $alias, integer: true),
            Aggregate::Sum => Measure::scalar('sum('.$this->numericField($aggregation)->sql.')', $alias),
            Aggregate::Avg => $this->windowAverage($aggregation, $alias, $measured),
            Aggregate::Min, Aggregate::Max => $this->windowExtreme($aggregation, $alias),
            Aggregate::P50, Aggregate::P75, Aggregate::P95, Aggregate::P99 => $this->windowPercentile($aggregation),
            Aggregate::FailureRate => $this->windowFailureRate($alias, $measured),
            Aggregate::CountUnique, Aggregate::Apdex => throw DiscoverException::unsupported(
                $this->dataset(),
                $aggregation->aggregate->value,
            ),
        };
    }

    /**
     * Der Mittelwert ist der Quotient zweier Summen.
     *
     * `avg()` über die Spalte wäre der Mittelwert der **Minuten** und nicht der
     * Aufrufe: eine Minute mit einem langsamen Aufruf zählte dann so viel wie eine
     * mit tausend schnellen.
     */
    private function windowAverage(Aggregation $aggregation, string $alias, string $measured): Measure
    {
        $field = $this->numericField($aggregation);
        $sum = 'sum('.$field->sql.')';

        return new Measure(
            [$sum.' as '.$alias.'_sum', $measured.' as '.$alias.'_measured'],
            static function (array $row) use ($alias): ?float {
                $count = (int) ($row[$alias.'_measured'] ?? 0);

                return $count === 0 ? null : (float) ($row[$alias.'_sum'] ?? 0) / $count;
            },
            '('.$sum.' / nullif('.$measured.', 0))',
        );
    }

    /**
     * Das Kleinste und Größte stehen als eigene Spalten in der Zeile — sie sind
     * die einzigen Kennzahlen, die sich über Fenster hinweg einfach fortsetzen.
     */
    private function windowExtreme(Aggregation $aggregation, string $alias): Measure
    {
        if ($aggregation->field !== 'duration') {
            throw DiscoverException::unsupported(
                $this->dataset(),
                $aggregation->aggregate->value.'('.(string) $aggregation->field.')',
            );
        }

        $column = $this->wrap('transaction_aggregates.duration_'.($aggregation->aggregate === Aggregate::Min ? 'min' : 'max').'_us');

        return Measure::scalar($aggregation->aggregate->value.'('.$column.')', $alias);
    }

    /**
     * Das Perzentil aus den summierten Klassen der abgelegten Verteilung — die
     * Rechnung, um derer willen die Verteilung überhaupt in der Zeile steht.
     */
    private function windowPercentile(Aggregation $aggregation): Measure
    {
        if ($aggregation->field !== 'duration') {
            throw DiscoverException::unsupported(
                $this->dataset(),
                'ein Perzentil über das Feld '.(string) $aggregation->field,
            );
        }

        $percentile = (float) $aggregation->aggregate->percentile();
        $prefix = self::bucketPrefix('duration');

        return new Measure(
            DurationHistogram::sumExpressions($this->wrap('transaction_aggregates.duration_histogram'), $prefix),
            static function (array $row) use ($percentile, $prefix): ?float {
                $value = DurationHistogram::fromRowSums($row, $prefix)->percentile($percentile);

                return $value === null ? null : (float) $value;
            },
        );
    }

    private function windowFailureRate(string $alias, string $measured): Measure
    {
        $failures = 'sum('.$this->wrap('transaction_aggregates.failure_count').')';

        return new Measure(
            [$failures.' as '.$alias.'_failures', $measured.' as '.$alias.'_measured'],
            static function (array $row) use ($alias): ?float {
                $count = (int) ($row[$alias.'_measured'] ?? 0);

                return $count === 0 ? null : (int) ($row[$alias.'_failures'] ?? 0) / $count * 100;
            },
            '('.$failures.' * 100.0 / nullif('.$measured.', 0))',
        );
    }
}
