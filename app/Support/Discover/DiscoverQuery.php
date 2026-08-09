<?php

namespace App\Support\Discover;

/**
 * Eine freie Auswertung, vollständig beschrieben — die interne Schnittstelle des
 * Motors.
 *
 * Ein Gegenstand und nicht zehn Parameter, aus drei Gründen: er lässt sich
 * **weitergeben** (die Oberfläche baut ihn aus der Adresszeile, der Alarm aus
 * seiner Definition), er lässt sich **vergleichen** (der Fingerabdruck ist der
 * Schlüssel des Zwischenspeichers), und er lässt sich **prüfen**, bevor eine
 * Datenbank angefasst wird.
 *
 * Die `with…()`-Methoden geben jeweils eine Kopie zurück. Das ist nicht Zierde:
 * eine Zeitreihe entsteht aus derselben Abfrage wie die Tabelle, nur mit
 * Schrittweite, und ein Vergleich mit der Vorwoche aus derselben mit verschobenem
 * Zeitraum. Wäre die Abfrage veränderlich, hinge das Ergebnis daran, wer sie
 * zuletzt in der Hand hatte.
 */
final class DiscoverQuery
{
    /**
     * @param  list<string>  $groupBy
     * @param  list<Aggregation>  $aggregations
     */
    private function __construct(
        public readonly Dataset $dataset,
        public readonly int $projectId,
        public readonly TimeRange $range,
        public readonly ?string $search = null,
        public readonly array $groupBy = [],
        public readonly array $aggregations = [],
        public readonly ?Ordering $orderBy = null,
        public readonly int $limit = 50,
        public readonly ?Interval $interval = null,
        public readonly string $timezone = 'UTC',
        public readonly bool $cacheable = true,
    ) {}

    public static function for(Dataset $dataset, int $projectId, TimeRange $range): self
    {
        return new self($dataset, $projectId, $range);
    }

    /**
     * Die Suchbedingung in der Sprache aus S4 — leer heißt „alles".
     */
    public function withSearch(?string $search): self
    {
        return $this->copy(search: (string) $search);
    }

    /**
     * @param  list<string>  $fields
     */
    public function groupedBy(array $fields): self
    {
        return $this->copy(groupBy: $fields);
    }

    /**
     * Die Kennzahlen — als Gegenstand oder in der Schreibweise `p95(duration)`.
     *
     * @param  list<Aggregation|string>  $aggregations
     */
    public function measuring(array $aggregations): self
    {
        return $this->copy(aggregations: array_map(
            static fn (Aggregation|string $aggregation): Aggregation => $aggregation instanceof Aggregation
                ? $aggregation
                : Aggregation::parse($aggregation),
            $aggregations,
        ));
    }

    public function orderedBy(Ordering|string $ordering): self
    {
        return $this->copy(orderBy: is_string($ordering) ? Ordering::parse($ordering) : $ordering);
    }

    public function limitedTo(int $limit): self
    {
        return $this->copy(limit: $limit);
    }

    /**
     * Macht aus der Abfrage eine Zeitreihe mit dieser Schrittweite.
     */
    public function every(Interval|string $interval): self
    {
        return $this->copy(interval: is_string($interval) ? Interval::parse($interval) : $interval);
    }

    public function inTimezone(string $timezone): self
    {
        return $this->copy(timezone: $timezone);
    }

    public function withRange(TimeRange $range): self
    {
        return $this->copy(range: $range);
    }

    /**
     * Ohne Zwischenspeicher — für Leser, die jede Ablesung frisch brauchen.
     *
     * Die Alarme (A3) sind der Fall: sie lesen jede Minute ein anderes Fenster,
     * würden also ohnehin nie treffen, und jeder Treffer wäre dort ein Fehler —
     * ein Alarm, der auf einer Minute alter Zahl auslöst, meldet die Vergangenheit.
     */
    public function uncached(): self
    {
        return $this->copy(cacheable: false);
    }

    /**
     * Der Schlüssel des Zwischenspeichers: gleiche Abfrage, gleicher Abdruck.
     *
     * Enthalten ist alles, was das Ergebnis verändert — und die Zeitzone
     * ausdrücklich mit, weil `firstSeen:2026-03-01` in einer anderen Zeitzone
     * einen anderen Tag meint.
     */
    public function fingerprint(): string
    {
        return sha1((string) json_encode([
            'dataset' => $this->dataset->value,
            'project' => $this->projectId,
            'range' => $this->range->toFingerprint(),
            'search' => (string) $this->search,
            'group_by' => $this->groupBy,
            'aggregations' => array_map(
                static fn (Aggregation $aggregation): string => $aggregation->toString(),
                $this->aggregations,
            ),
            'order_by' => $this->orderBy === null ? null : [$this->orderBy->key, $this->orderBy->descending],
            'limit' => $this->limit,
            'interval' => $this->interval?->key,
            'timezone' => $this->timezone,
        ]));
    }

    /**
     * Eine Kopie mit einzelnen Änderungen: `null` heißt „unverändert".
     *
     * Bei der Suche heißt eine **leere Zeichenkette** „gelöscht" — die beiden
     * Fälle sind hier nicht dasselbe, und ohne die Unterscheidung könnte
     * {@see self::withSearch()} eine Bedingung nicht mehr abnehmen. In den Feldern
     * abgelegt wird sie trotzdem als `null`, damit zwei Abfragen ohne Suche auch
     * denselben Fingerabdruck haben.
     *
     * @param  list<string>|null  $groupBy
     * @param  list<Aggregation>|null  $aggregations
     */
    private function copy(
        ?TimeRange $range = null,
        ?string $search = null,
        ?array $groupBy = null,
        ?array $aggregations = null,
        ?Ordering $orderBy = null,
        ?int $limit = null,
        ?Interval $interval = null,
        ?string $timezone = null,
        ?bool $cacheable = null,
    ): self {
        if ($search !== null) {
            $search = trim($search) === '' ? null : trim($search);
        } else {
            $search = $this->search;
        }

        return new self(
            $this->dataset,
            $this->projectId,
            $range ?? $this->range,
            $search,
            $groupBy ?? $this->groupBy,
            $aggregations ?? $this->aggregations,
            $orderBy ?? $this->orderBy,
            $limit ?? $this->limit,
            $interval ?? $this->interval,
            $timezone ?? $this->timezone,
            $cacheable ?? $this->cacheable,
        );
    }
}
