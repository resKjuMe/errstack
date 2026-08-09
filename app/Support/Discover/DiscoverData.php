<?php

namespace App\Support\Discover;

use App\Support\Filters\GlobalFilter;
use App\Support\Formats;

/**
 * Das Ergebnis des Motors, wie die Oberfläche es braucht — und wie es in eine
 * CSV-Datei geht.
 *
 * **Die Spalten entstehen genau einmal.** Tabelle, Diagramm und Ausgabe zeigen
 * dieselbe Abfrage; würde jede sich ihre Spalten selbst zusammensuchen, wären es
 * drei Listen, die auseinanderlaufen — und die Zusage „die CSV-Datei enthält
 * genau die angezeigten Spalten" wäre nicht mehr zu halten. Deshalb liefert
 * {@see self::columns()} die eine Liste, an der sich alle drei bedienen.
 *
 * **Gerechnete Zahlen bleiben Zahlen.** Was hierher kommt, ist der Wert des
 * Motors und keine fertige Zeichenkette: das Diagramm zeichnet damit, und die
 * Oberfläche schreibt ihn in der Einheit, in der die ganze Anwendung ihn schreibt
 * (`shell/duration.js`). Die Spalte sagt dazu, **was** die Zahl ist
 * (`format`, `unit`) — nicht, wie sie auszusehen hat. Nur die CSV-Datei
 * formatiert selbst, weil sie ohne Browser entsteht; sie nennt die Einheit
 * deshalb in der Kopfzeile.
 */
final class DiscoverData
{
    /**
     * Die Spalten einer Abfrage: erst die Gruppierung, dann die Kennzahlen.
     *
     * @return list<array{key: string, label: string, kind: string, unit: string, format: string}>
     */
    public static function columns(DiscoverQuery $query): array
    {
        $fields = $query->dataset->fields($query->timezone);
        $columns = [];

        foreach ($query->groupBy as $field) {
            $columns[] = [
                'key' => $field,
                'label' => $field,
                'kind' => 'group',
                'unit' => '',
                'format' => 'text',
            ];
        }

        foreach ($query->aggregations as $aggregation) {
            $type = $aggregation->field === null
                ? null
                : $fields->definition($aggregation->field)?->type;

            $columns[] = [
                'key' => $aggregation->alias(),
                'label' => self::label($aggregation),
                'kind' => 'metric',
                'unit' => self::unit($aggregation, $type),
                'format' => self::format($aggregation, $type),
            ];
        }

        return $columns;
    }

    /**
     * Die Tabelle: Zeilen samt dem Weg zu den Ereignissen dahinter.
     *
     * @return array{rows: list<array{groups: array<string, string|null>, values: array<string, float|null>, href: string|null}>, truncated: bool, cached: bool, unavailable: list<string>, searchError: array{message: string, position: int, excerpt: string}|null}
     */
    public static function table(
        DiscoverResult $result,
        DiscoverQuery $query,
        GlobalFilter $filter,
        string $projectSlug,
    ): array {
        $rows = [];

        foreach ($result->rows as $row) {
            $rows[] = [
                'groups' => $row->groups,
                'values' => $row->values,
                'href' => DiscoverDrilldown::href(
                    $query->dataset,
                    $query->groupBy,
                    $row->groups,
                    (string) $query->search,
                    $filter,
                    $projectSlug,
                ),
            ];
        }

        return [
            'rows' => $rows,
            'truncated' => $result->truncated,
            'cached' => $result->cached,
            'unavailable' => $result->unavailable,
            'searchError' => $result->searchError,
        ];
    }

    /**
     * Die Zeitreihe: eine Linie je Tabellenzeile, alle auf demselben Raster.
     *
     * Die Zeitpunkte stehen **einmal** daneben und nicht an jedem Punkt jeder
     * Linie: die Reihen sind lückenlos und teilen sich damit dieselbe Achse.
     *
     * @return array{interval: string, at: list<string>, lines: list<array{key: string, label: string, values: array<string, list<float|null>>}>, truncated: bool, cached: bool}
     */
    public static function series(DiscoverSeries $series, string $totalLabel): array
    {
        $first = $series->first();
        $at = [];

        foreach ($first === null ? [] : $first->points as $point) {
            $at[] = $point->at->toIso8601ZuluString();
        }

        $lines = [];

        foreach ($series->groups as $group) {
            $values = [];

            foreach ($series->aliases as $alias) {
                $values[$alias] = array_map(
                    static fn (SeriesPoint $point): ?float => $point->values[$alias] ?? null,
                    $group->points,
                );
            }

            $label = self::lineLabel($group, $series->groupBy, $totalLabel);

            $lines[] = ['key' => $label, 'label' => $label, 'values' => $values];
        }

        return [
            'interval' => $series->interval->key,
            'at' => $at,
            'lines' => $lines,
            'truncated' => $series->truncated,
            'cached' => $series->cached,
        ];
    }

    /**
     * Was die Oberfläche zur Auswahl stellt: Quellen mit ihren Feldern,
     * Rechenarten, Schrittweiten und die Grenzen, an denen eine Abfrage endet.
     *
     * Alles davon kommt vom Server, damit die Auswahlfelder genau das anbieten,
     * was der Motor auch annimmt — eine Liste, die sich anklicken lässt und
     * abgewiesen wird, wäre die Folge zweier getrennter Kataloge.
     *
     * @return array<string, mixed>
     */
    public static function catalog(string $timezone, DiscoverLimits $limits): array
    {
        return [
            'datasets' => Dataset::options($timezone),
            'aggregates' => array_map(
                static fn (Aggregate $aggregate): array => [
                    'value' => $aggregate->value,
                    'label' => $aggregate->label(),
                    'needsField' => $aggregate->needsField(),
                ],
                Aggregate::cases(),
            ),
            'intervals' => array_map(
                static fn (string $key): array => ['value' => $key, 'label' => $key],
                Interval::options(),
            ),
            'limits' => [
                'maxRows' => $limits->maxRows,
                'maxGroupFields' => $limits->maxGroupFields,
                'maxAggregations' => $limits->maxAggregations,
                'maxSeriesGroups' => $limits->maxSeriesGroups,
                'maxRangeDays' => $limits->maxRangeDays,
            ],
        ];
    }

    /**
     * Eine Zelle für die CSV-Datei: dieselbe Zahl wie in der Tabelle, in der
     * Schreibweise der gewählten Sprache.
     *
     * Nachkommastellen nur, wo es welche gibt — eine Anzahl als „812,00" wäre
     * eine Genauigkeit, die niemand behauptet hat.
     */
    public static function cell(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return Formats::number($value, self::isWhole($value) ? 0 : 2);
    }

    /**
     * Die Kopfzeile der CSV-Datei: die Beschriftung der Spalte und, wo es eine
     * gibt, ihre Einheit. Ohne sie wäre „1834" eine Zahl ohne Aussage.
     *
     * @param  array{key: string, label: string, kind: string, unit: string, format: string}  $column
     */
    public static function heading(array $column): string
    {
        return $column['unit'] === '' ? $column['label'] : $column['label'].' ['.$column['unit'].']';
    }

    private static function label(Aggregation $aggregation): string
    {
        $label = $aggregation->aggregate->label();

        return $aggregation->field === null ? $label : $label.' ('.$aggregation->field.')';
    }

    private static function unit(Aggregation $aggregation, ?FieldType $type): string
    {
        if ($aggregation->aggregate->unit() !== '') {
            return $aggregation->aggregate->unit();
        }

        // Eine Anzahl **verschiedener** Dauern ist keine Dauer, und eine Summe
        // von Dauern ist eine — die Einheit hängt an der Rechenart und nicht
        // allein am Feld.
        return $type === FieldType::Duration && self::keepsUnit($aggregation->aggregate) ? 'µs' : '';
    }

    private static function format(Aggregation $aggregation, ?FieldType $type): string
    {
        if ($aggregation->aggregate === Aggregate::FailureRate) {
            return 'percent';
        }

        if ($aggregation->aggregate === Aggregate::Apdex) {
            return 'ratio';
        }

        return $type === FieldType::Duration && self::keepsUnit($aggregation->aggregate)
            ? 'duration'
            : 'number';
    }

    /**
     * Behält die Rechenart die Einheit ihres Feldes?
     */
    private static function keepsUnit(Aggregate $aggregate): bool
    {
        return $aggregate !== Aggregate::CountUnique;
    }

    /**
     * @param  list<string>  $groupBy
     */
    private static function lineLabel(SeriesGroup $group, array $groupBy, string $totalLabel): string
    {
        if ($groupBy === []) {
            return $totalLabel;
        }

        return implode(' · ', array_map(
            static fn (string $field): string => (string) ($group->groups[$field] ?? '—'),
            $groupBy,
        ));
    }

    private static function isWhole(float $value): bool
    {
        return abs($value - round($value)) < 0.0000001;
    }
}
