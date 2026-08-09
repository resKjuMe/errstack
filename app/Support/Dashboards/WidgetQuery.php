<?php

namespace App\Support\Dashboards;

use App\Http\Requests\DiscoverRequest;
use App\Support\Discover\Aggregation;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverLimits;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\Interval;
use App\Support\Discover\TimeRange;

/**
 * Die gespeicherte Abfrage einer Kachel — dieselben sieben Angaben, die in der
 * Adresszeile der freien Auswertung stehen.
 *
 * **Es ist bewusst dieselbe Beschreibung und keine zweite.** Eine Kachel ist
 * eine festgehaltene freie Auswertung; wäre sie hier anders beschrieben, gäbe es
 * zwei Stellen, an denen „was heißt eine Abfrage" beantwortet wird — und der Weg
 * von der Auswertung auf ein Dashboard (und zurück) wäre eine Übersetzung
 * zwischen ihnen. Die Schreibweise der Kennzahlen (`p95(duration)`) ist deshalb
 * die des Motors, und die Voreinstellungen sind die von
 * {@see DiscoverRequest}.
 *
 * **Gelesen wird nachsichtig, gerechnet streng.** Was aus der Datenbank kommt,
 * ist Monate alt: ein Feld kann inzwischen fortgefallen sein, eine Quelle
 * umbenannt. {@see self::fromArray()} nimmt deshalb nur, was es versteht, und
 * fällt sonst auf die Voreinstellung zurück — eine Kachel, die sich nicht mehr
 * öffnen lässt, wäre die schlechtere Antwort. Ob die Abfrage *rechenbar* ist,
 * entscheidet weiterhin allein der Motor, wenn sie bei ihm ankommt.
 */
final class WidgetQuery
{
    /**
     * @param  list<string>  $fields
     * @param  list<string>  $metrics
     */
    private function __construct(
        public readonly Dataset $dataset,
        public readonly array $fields,
        public readonly array $metrics,
        public readonly string $search,
        public readonly string $sort,
        public readonly int $limit,
        public readonly ?string $interval,
    ) {}

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $metrics
     */
    public static function make(
        Dataset $dataset,
        array $fields = [],
        array $metrics = [],
        string $search = '',
        string $sort = '',
        int $limit = DiscoverRequest::DEFAULT_LIMIT,
        ?string $interval = null,
    ): self {
        $metrics = self::strings($metrics);

        return new self(
            $dataset,
            self::strings($fields),
            $metrics === [] ? [DiscoverRequest::DEFAULT_METRIC] : $metrics,
            trim($search),
            trim($sort),
            max(1, $limit),
            $interval === null || $interval === '' ? null : $interval,
        );
    }

    /**
     * Die Abfrage, wie sie in der Datenbank steht.
     *
     * @param  mixed  $value  der `query`-Inhalt der Kachel
     */
    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];

        $dataset = is_string($value['dataset'] ?? null)
            ? Dataset::tryFrom($value['dataset'])
            : null;

        $interval = is_string($value['interval'] ?? null) ? $value['interval'] : null;

        return self::make(
            dataset: $dataset ?? Dataset::Errors,
            fields: is_array($value['fields'] ?? null) ? $value['fields'] : [],
            metrics: is_array($value['metrics'] ?? null) ? $value['metrics'] : [],
            search: is_string($value['q'] ?? null) ? $value['q'] : '',
            sort: is_string($value['sort'] ?? null) ? $value['sort'] : '',
            limit: is_numeric($value['limit'] ?? null) ? (int) $value['limit'] : DiscoverRequest::DEFAULT_LIMIT,
            // Eine Schrittweite, die es nicht mehr gibt, ist keine: dann schlägt
            // die Kachel wieder die zum Zeitraum passende vor.
            interval: in_array($interval, Interval::options(), true) ? $interval : null,
        );
    }

    /**
     * @return array{dataset: string, fields: list<string>, metrics: list<string>, q: string, sort: string, limit: int, interval: string|null}
     */
    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset->value,
            'fields' => $this->fields,
            'metrics' => $this->metrics,
            'q' => $this->search,
            'sort' => $this->sort,
            'limit' => $this->limit,
            'interval' => $this->interval,
        ];
    }

    /**
     * Die Sortierung, die der Motor bekommt: die eingestellte, sonst die erste
     * Kennzahl absteigend — „das Größte zuerst", wie in der freien Auswertung.
     */
    public function ordering(): string
    {
        if ($this->sort !== '') {
            return $this->sort;
        }

        return '-'.Aggregation::parse($this->metrics[0])->alias();
    }

    /**
     * Die Abfrage für den Motor.
     *
     * `$search` ist die Bedingung, die die Umgebung der Filterleiste beisteuert;
     * sie wird mit der gespeicherten mit UND verbunden — dieselbe Regel wie in
     * der freien Auswertung, damit eine Kachel in einer Umgebung nicht mehr
     * zeigt als die Seite daneben.
     *
     * @throws DiscoverException wenn eine Kennzahl oder die Sortierung nicht zu lesen ist
     */
    public function toDiscoverQuery(
        int $projectId,
        TimeRange $range,
        string $timezone,
        DiscoverLimits $limits,
        string $search = '',
        int $limitOverride = 0,
    ): DiscoverQuery {
        $parts = array_values(array_filter([$this->search, trim($search)], static fn (string $part): bool => $part !== ''));

        $limit = $limitOverride > 0 ? $limitOverride : $this->limit;

        return DiscoverQuery::for($this->dataset, $projectId, $range)
            ->withSearch(implode(' ', $parts))
            ->groupedBy($this->fields)
            ->measuring($this->metrics)
            ->orderedBy($this->ordering())
            ->limitedTo(max(1, min($limit, $limits->maxRows)))
            ->inTimezone($timezone);
    }

    /**
     * Die Schrittweite eines Verlaufs: die eingestellte, sonst die zum Zeitraum
     * passende.
     *
     * Dieselbe Rechnung wie in der freien Auswertung — die feinste, die den
     * Zeitraum in höchstens {@see self::TARGET_POINTS} Stützstellen zerlegt. Eine
     * Kachel, die über „letzte Stunde" und über „letzte 30 Tage" dieselbe
     * Schrittweite nähme, wäre in einem der beiden Fälle unlesbar.
     */
    public function intervalFor(TimeRange $range): Interval
    {
        if ($this->interval !== null) {
            return Interval::parse($this->interval);
        }

        return Interval::fitting($range, self::TARGET_POINTS);
    }

    /**
     * Stützstellen, auf die die vorgeschlagene Schrittweite zielt.
     *
     * Weniger als in der freien Auswertung (dort hundert): eine Kachel ist ein
     * Ausschnitt des Bildschirms und keine Seite, und hundert Punkte auf einer
     * halben Kachelbreite sind ein Strich.
     */
    private const TARGET_POINTS = 60;

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = array_map(static fn (mixed $value): string => trim((string) $value), $values);

        return array_values(array_filter($strings, static fn (string $value): bool => $value !== ''));
    }
}
