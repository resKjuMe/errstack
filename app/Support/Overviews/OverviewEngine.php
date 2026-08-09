<?php

namespace App\Support\Overviews;

use App\Support\Discover\Aggregate;
use App\Support\Discover\Aggregation;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverEngine;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\Interval;
use App\Support\Discover\TimeRange;
use App\Support\Filters\GlobalFilter;
use App\Support\Search\SearchQuery;
use Carbon\CarbonImmutable;

/**
 * Die Zahlen der Übersichtsseiten — und die einzige Stelle, an der sie
 * entstehen.
 *
 * **Der Motor aus D1 und kein zweiter.** Die Zusage der Aufgabe ist knapp: die
 * Übersichten rechnen nicht selbst. Sie haben deshalb keine eigene Abfrage,
 * sondern stellen dem Motor dieselben Fragen, die auch eine Kachel oder ein
 * Alarm stellt — mit dem einen Unterschied, dass eine Übersicht **mehrere**
 * Projekte auf einmal meint.
 *
 * **Mehrere Projekte sind mehrere Abfragen.** Der Motor rechnet je Projekt
 * ({@see DiscoverQuery::$projectId}), weil die Grenzen, der Zwischenspeicher und
 * die Rechte daran hängen. Was hier dazukommt, ist deshalb nur das
 * Zusammenlegen — und das ausschließlich für Kennzahlen, bei denen es
 * überhaupt eine Bedeutung hat: eine Anzahl darf man addieren, ein Perzentil
 * nicht. Genau das erzwingt {@see self::additive()}: eine über drei Projekte
 * „gemittelte" p95 wäre eine Zahl, die niemand nachrechnen kann, und sie stünde
 * neben Zahlen, die stimmen.
 *
 * **Wo ein Projekt fehlt, fehlt die Zahl und nicht die Seite.** Lehnt der Motor
 * eine Abfrage ab (Grenze überschritten, Quelle nicht verfügbar), gibt das die
 * betroffene Kachel als Auskunft weiter; die übrigen stehen unverändert da.
 */
final class OverviewEngine
{
    /**
     * Stützstellen, auf die die Verläufe der Übersichten zielen.
     *
     * Wie bei einer Dashboard-Kachel und nicht wie in der freien Auswertung:
     * ein Verlauf auf einer Übersicht ist ein Streifen neben anderen Kacheln
     * und keine Seite, und hundert Punkte darauf wären ein Strich.
     */
    private const TARGET_POINTS = 60;

    public function __construct(private readonly DiscoverEngine $engine = new DiscoverEngine) {}

    /**
     * Ein Verlauf über alle genannten Projekte: je Projekt eine Abfrage, die
     * Summe je Stützstelle.
     *
     * Alle Abfragen teilen sich Zeitraum und Schrittweite, also auch das
     * Raster — die Stützstellen lassen sich deshalb der Reihe nach addieren,
     * ohne Zeitpunkte zu vergleichen.
     *
     * @param  list<int>  $projectIds
     * @return array{at: list<string>, values: list<float|null>, interval: string}
     *
     * @throws DiscoverException
     */
    public function series(
        Dataset $dataset,
        array $projectIds,
        GlobalFilter $filter,
        Aggregation|string $aggregation,
    ): array {
        $aggregation = self::additive($aggregation);
        $range = self::range($filter);
        $interval = Interval::fitting($range, self::TARGET_POINTS);

        $at = array_map(
            static fn (CarbonImmutable $bucket): string => $bucket->toIso8601ZuluString(),
            $interval->buckets($range),
        );

        // Zusammengelegt wird über den Zeitpunkt und nicht über die Position in
        // der Liste: beide Reihen entstehen zwar aus demselben Raster, aber
        // eine Summe, die an einer verschobenen Reihe stillschweigend die
        // falschen Punkte addiert, wäre von einer richtigen nicht zu
        // unterscheiden.
        $sums = array_fill_keys($at, null);

        foreach ($projectIds as $projectId) {
            $series = $this->engine->series(
                $this->query($dataset, $projectId, $range, $filter, $aggregation)->every($interval),
            );

            foreach ($series->first()?->points ?? [] as $point) {
                $key = $point->at->toIso8601ZuluString();

                if (! array_key_exists($key, $sums)) {
                    continue;
                }

                $sums[$key] = self::add($sums[$key], $point->values[$aggregation->alias()] ?? null);
            }
        }

        return ['at' => $at, 'values' => array_values($sums), 'interval' => $interval->key];
    }

    /**
     * Dieselbe Kennzahl je Projekt — die Grundlage jeder Rangliste über
     * Projekte hinweg.
     *
     * Gruppiert wird **nicht**: die Projektzugehörigkeit ist kein Feld des
     * Motors, sondern der Rahmen, in dem er rechnet. Eine Zeile je Projekt
     * entsteht deshalb aus einer Abfrage je Projekt.
     *
     * @param  list<int>  $projectIds
     * @return array<int, float|null> Projekt-id auf Wert
     *
     * @throws DiscoverException
     */
    public function perProject(
        Dataset $dataset,
        array $projectIds,
        GlobalFilter $filter,
        Aggregation|string $aggregation,
    ): array {
        $aggregation = $aggregation instanceof Aggregation ? $aggregation : Aggregation::parse($aggregation);
        $range = self::range($filter);
        $values = [];

        foreach ($projectIds as $projectId) {
            $result = $this->engine->table(
                $this->query($dataset, $projectId, $range, $filter, $aggregation)->limitedTo(1),
            );

            $values[$projectId] = $result->rows[0]->values[$aggregation->alias()] ?? null;
        }

        return $values;
    }

    /**
     * Die Abfrage, die alle Übersichts-Zahlen gemeinsam haben.
     *
     * Die Umgebung wird zur Suchbedingung und nicht zu einem zweiten Weg,
     * eine Auswertung einzuschränken — dieselbe Entscheidung wie in
     * {@see App\Support\Dashboards\WidgetData} und
     * {@see App\Http\Requests\DiscoverRequest}.
     */
    private function query(
        Dataset $dataset,
        int $projectId,
        TimeRange $range,
        GlobalFilter $filter,
        Aggregation $aggregation,
    ): DiscoverQuery {
        return DiscoverQuery::for($dataset, $projectId, $range)
            ->withSearch($filter->environment === null ? '' : SearchQuery::term('environment', $filter->environment))
            ->measuring([$aggregation])
            ->inTimezone($filter->timezone);
    }

    /**
     * Der Zeitraum der Filterleiste als Zeitraum des Motors.
     */
    private static function range(GlobalFilter $filter): TimeRange
    {
        return TimeRange::of($filter->fromUtc(), $filter->toUtc());
    }

    /**
     * Nur Kennzahlen, die sich über Projekte hinweg addieren lassen.
     *
     * Eine Anzahl und eine Summe schon: „412 Fehler hier und 88 dort" sind 500.
     * Ein Perzentil, ein Mittelwert und eine eindeutige Zählung nicht — deren
     * Summe wäre eine Zahl ohne Bedeutung, und ein gewichteter Mittelwert wäre
     * eine zweite Rechnung neben dem Motor. Wer sie je Projekt braucht, fragt
     * {@see self::perProject()}.
     */
    private static function additive(Aggregation|string $aggregation): Aggregation
    {
        $aggregation = $aggregation instanceof Aggregation ? $aggregation : Aggregation::parse($aggregation);

        if (! in_array($aggregation->aggregate, [Aggregate::Count, Aggregate::Sum], true)) {
            throw DiscoverException::invalid(
                'Über mehrere Projekte lassen sich nur Anzahlen und Summen zusammenlegen: '.$aggregation->toString(),
            );
        }

        return $aggregation;
    }

    /**
     * Zwei Werte addieren, ohne aus „keine Daten" eine Null zu machen.
     *
     * `null` heißt hier nicht „0", sondern „dazu liegt nichts vor" — und wenn
     * kein einziges Projekt etwas beiträgt, bleibt es dabei. Sobald eines eine
     * Zahl liefert, ist die Summe eine Zahl.
     */
    private static function add(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        return $right === null ? $left : $left + $right;
    }
}
