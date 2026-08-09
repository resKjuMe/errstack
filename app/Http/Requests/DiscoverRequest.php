<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Discover\Aggregation;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverLimits;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\Interval;
use App\Support\Discover\TimeRange;
use App\Support\Filters\GlobalFilter;
use App\Support\Search\SearchQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Eingabe der freien Auswertung: die globale Filterleiste plus die Abfrage
 * selbst — Quelle, Gruppierung, Kennzahlen, Suchbedingung, Sortierung, Zeilenzahl
 * und Schrittweite.
 *
 * **Der ganze Abfragezustand steht in der Adresszeile**, nach denselben Regeln
 * wie überall: ein Neuladen behält ihn, und ein geteilter Link zeigt beim
 * Empfänger dieselbe Auswertung. Genau deshalb liegt hier auch die Schreibweise
 * fest, in der eine Kennzahl in der Adresse steht (`p95(duration)`) — es ist die
 * des Motors ({@see Aggregation}) und dieselbe, die später
 * in einem gespeicherten Baustein und in einer Alarmdefinition steht.
 *
 * **Die Umgebung der Filterleiste wird zur Suchbedingung.** Der Motor kennt
 * genau einen Weg, eine Auswertung einzuschränken: die Suchsprache aus S4. Ein
 * zweiter Weg — „Umgebung" als eigener Parameter — wäre eine zweite Stelle, an
 * der eine Auswertung enger wird, und die beiden könnten sich widersprechen.
 * Kennt eine Quelle das Feld nicht (die Rückmeldungen etwa), sagt das Ergebnis
 * es als `unavailable`, statt stillschweigend ungefiltert dazustehen.
 */
class DiscoverRequest extends GlobalFilterRequest
{
    /** Längste Sucheingabe — dieselbe Großzügigkeit wie in der Fehlerliste. */
    public const SEARCH_LIMIT = 500;

    /** Die Kennzahl, mit der eine leere Auswertung beginnt. */
    public const DEFAULT_METRIC = 'count()';

    /** Zeilen, wenn nichts anderes dasteht. */
    public const DEFAULT_LIMIT = 50;

    /**
     * Stützstellen, auf die die vorgeschlagene Schrittweite zielt.
     *
     * Kein Grenzwert, sondern ein Geschmack: rund hundert Balken sind auf einem
     * Bildschirm noch zu unterscheiden und über einen Zeitraum von Stunden bis
     * Wochen fein genug. Die harte Grenze steht in
     * {@see DiscoverLimits::$maxPoints} und wird davon nicht berührt.
     */
    private const TARGET_POINTS = 100;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Zusammengeführt und nicht ersetzt: die Felder der Filterleiste müssen
        // stehen bleiben, sonst fielen sie aus `validated()` heraus und die
        // Seite zeigte den Standard-Zeitraum, egal was in der Adresse steht.
        return parent::rules() + [
            'dataset' => ['nullable', Rule::enum(Dataset::class)],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'max:255'],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['string', 'max:255'],
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_LIMIT],
            'sort' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'interval' => ['nullable', Rule::in(Interval::options())],
        ];
    }

    public function dataset(): Dataset
    {
        $value = $this->validated('dataset');

        return is_string($value) ? Dataset::from($value) : Dataset::Errors;
    }

    /**
     * Die Gruppierungsfelder, in der Reihenfolge der Adresszeile.
     *
     * Leere Einträge fallen heraus: ein Auswahlfeld, das auf „—" steht, ist kein
     * Feld, und der Motor würde es als unbekanntes zurückweisen.
     *
     * @return list<string>
     */
    public function groupBy(): array
    {
        return $this->strings($this->validated('fields'));
    }

    /**
     * Die Kennzahlen in ihrer Schreibweise (`count()`, `p95(duration)`).
     *
     * Ohne Angabe die Anzahl: eine Auswertung ohne Kennzahl ist keine, und die
     * Anzahl ist die einzige, die jede Quelle rechnen kann.
     *
     * @return list<string>
     */
    public function metrics(): array
    {
        $metrics = $this->strings($this->validated('metrics'));

        return $metrics === [] ? [self::DEFAULT_METRIC] : $metrics;
    }

    /**
     * Die Sucheingabe, wie sie in das Feld zurückgeschrieben wird — ohne die
     * Umgebung aus der Filterleiste, die niemand dort getippt hat.
     */
    public function searchInput(): string
    {
        $input = $this->validated('q');

        return is_string($input) ? trim($input) : '';
    }

    /**
     * Die Sortierung als Schlüssel der Ergebniszeile (`-count`, `browser`).
     *
     * Ohne Angabe die erste Kennzahl, absteigend: „das Größte zuerst" ist die
     * Frage, mit der jemand eine Rangliste aufschlägt.
     */
    public function sort(): string
    {
        $sort = $this->validated('sort');

        if (is_string($sort) && trim($sort) !== '') {
            return trim($sort);
        }

        return '-'.Aggregation::parse($this->metrics()[0])->alias();
    }

    /**
     * Dieselbe Sortierung, aber ohne Anspruch: leer, wenn die erste Kennzahl
     * nicht zu lesen ist.
     *
     * Gebraucht für die Felder der Leiste. Sie werden auch dann gezeichnet, wenn
     * die Abfrage abgelehnt wurde — und genau dann darf das Zurückschreiben des
     * Zustands nicht seinerseits an derselben Kennzahl scheitern.
     */
    public function sortInput(): string
    {
        try {
            return $this->sort();
        } catch (DiscoverException) {
            $sort = $this->validated('sort');

            return is_string($sort) ? trim($sort) : '';
        }
    }

    public function limit(DiscoverLimits $limits): int
    {
        $limit = $this->validated('limit');
        $limit = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;

        return max(1, min($limit, $limits->maxRows));
    }

    /**
     * Die Schrittweite des Diagramms — die gewählte, sonst die zum Zeitraum
     * passende.
     *
     * Nicht `interval()`: so heißt am Request bereits das Zeitfenster einer
     * Eingabe, und eine abweichende Unterschrift darunter ist ein Fehler beim
     * Laden der Klasse — dieselbe Falle wie bei `query()`.
     *
     * Vorgeschlagen wird die **feinste**, die den Zeitraum in höchstens
     * {@see self::TARGET_POINTS} Stützstellen zerlegt. Andersherum — die
     * gröbste, die noch unter der Grenze bleibt — käme bei „letzte Stunde" auf
     * eine Woche je Balken.
     */
    public function chartInterval(GlobalFilter $filter): Interval
    {
        $chosen = $this->validated('interval');

        if (is_string($chosen) && $chosen !== '') {
            return Interval::parse($chosen);
        }

        $range = $this->range($filter);

        foreach (Interval::options() as $key) {
            $interval = Interval::parse($key);

            if ($interval->points($range) <= self::TARGET_POINTS) {
                return $interval;
            }
        }

        return Interval::parse('7d');
    }

    /**
     * Der Zeitraum der Filterleiste als Zeitraum des Motors.
     */
    public function range(GlobalFilter $filter): TimeRange
    {
        return TimeRange::of($filter->fromUtc(), $filter->toUtc());
    }

    /**
     * Die vollständige Abfrage — ohne Schrittweite; die Zeitreihe entsteht daraus
     * mit {@see DiscoverQuery::every()}.
     *
     * Nicht `query()`: so heißt am Request bereits der Zugriff auf die
     * Adresszeile, und ein überschriebenes `query()` würde jede Stelle im
     * Rahmenwerk treffen, die ihn benutzt.
     */
    public function discoverQuery(Project $project, GlobalFilter $filter, DiscoverLimits $limits): DiscoverQuery
    {
        return DiscoverQuery::for($this->dataset(), $project->id, $this->range($filter))
            ->withSearch($this->search($filter))
            ->groupedBy($this->groupBy())
            ->measuring($this->metrics())
            ->orderedBy($this->sort())
            ->limitedTo($this->limit($limits))
            ->inTimezone($filter->timezone);
    }

    /**
     * Die Suchbedingung, die der Motor bekommt: die getippte und die Umgebung
     * aus der Filterleiste, mit UND verbunden.
     */
    public function search(GlobalFilter $filter): string
    {
        $parts = [];

        if ($this->searchInput() !== '') {
            $parts[] = $this->searchInput();
        }

        if ($filter->environment !== null) {
            $parts[] = SearchQuery::term('environment', $filter->environment);
        }

        return implode(' ', $parts);
    }

    /**
     * Der Zustand der Abfrage-Leiste, wie die Oberfläche ihn in ihren Feldern
     * führt — und wie er in einem Link wieder in die Adresse geht.
     *
     * @return array{dataset: string, fields: list<string>, metrics: list<string>, q: string, sort: string, limit: int, interval: string}
     */
    public function queryValues(GlobalFilter $filter, DiscoverLimits $limits): array
    {
        return [
            'dataset' => $this->dataset()->value,
            'fields' => $this->groupBy(),
            'metrics' => $this->metrics(),
            'q' => $this->searchInput(),
            'sort' => $this->sortInput(),
            'limit' => $this->limit($limits),
            'interval' => $this->chartInterval($filter)->key,
        ];
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = array_map(static fn (mixed $value): string => trim((string) $value), $values);

        return array_values(array_filter($strings, static fn (string $value): bool => $value !== ''));
    }
}
