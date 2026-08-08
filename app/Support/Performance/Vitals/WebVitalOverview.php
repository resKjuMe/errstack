<?php

namespace App\Support\Performance\Vitals;

use App\Enums\WebVital;
use App\Models\TransactionAggregate;
use App\Models\WebVitalAggregate;
use App\Support\Filters\GlobalFilter;
use App\Support\Performance\TransactionSearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Abfrage hinter der Web-Vitals-Übersicht: aus Filter und Suche werden die
 * Seiten, sortiert nach dem größten Problem.
 *
 * **Die Zusage ist dieselbe wie bei der Performance-Übersicht: eine feste Zahl
 * an Abfragen, unabhängig von der Datenmenge.** Genau drei, und keine davon
 * berührt die Einzelmessungen:
 *
 *   1. die Messwerte des gewählten Zeitraums aus `web_vital_aggregates`,
 *      gruppiert nach Seite und Messwert,
 *   2. dieselben Summen des Vorzeitraums, nur so weit für den Trend nötig,
 *   3. die Seiten, für die es Ladevorgänge, aber keinen einzigen Messwert gibt.
 *
 * Die dritte ist die, die man weglassen könnte — und genau das wäre der Fehler,
 * den diese Seite nicht machen darf. Ohne sie stünde eine Seite, deren SDK keine
 * Messwerte schickt, überhaupt nicht in der Liste, und ihr Fehlen sähe aus wie
 * „alles in Ordnung". Die Auskunft „hier wird nicht gemessen" ist die
 * dringendere von beiden: sie ist mit einer Zeile Einbindung zu beheben,
 * während ein schlechtes LCP Arbeit bedeutet.
 *
 * Was danach in PHP passiert (Perzentile, Bewertung, Rangfolge, Blättern),
 * rechnet über **eine Zeile je Seite und Messwert**, nicht je Messung.
 */
final class WebVitalOverview
{
    /**
     * Zeilen je Seite — wie in der Performance-Übersicht.
     */
    public const PER_PAGE = 25;

    /**
     * Wie viele Seiten überhaupt zusammengetragen werden.
     *
     * Dieselbe Überlegung wie bei den Antwortzeiten: eine Anwendung, die ihre
     * Namen nicht im Griff hat, erzeugt Zehntausende Gruppen. Ehrlich
     * abgeschnitten ist besser als vollständig und unbenutzbar — und die
     * Oberfläche sagt es dazu.
     */
    public const GROUP_LIMIT = 500;

    /**
     * Die Vorgangsarten, unter denen ein Browser eine Seite meldet.
     *
     * Sie entscheiden, welche Seiten in Abfrage 3 als „gemessen, aber ohne
     * Messwerte" gelten. Ohne diese Einschränkung stünde jeder serverseitige
     * Endpunkt in der Liste — mit dem Hinweis, für ihn fehlten Browser-Messwerte,
     * was für einen Hintergrundauftrag eine sinnlose Auskunft wäre.
     */
    public const BROWSER_OPS = ['pageload', 'navigation'];

    public function __construct(
        private readonly GlobalFilter $filter,
        private readonly string $search,
    ) {}

    /**
     * Die angeforderte Seite der Übersicht.
     */
    public function page(int $page): WebVitalOverviewResult
    {
        $current = $this->currentMetrics();
        $names = array_values(array_unique(array_map(
            static fn (array $metrics): string => $metrics['name'],
            $current,
        )));

        $previous = $names === [] ? [] : $this->previousMetrics($names);

        /** @var array<string, array<string, VitalSummary>> $pages */
        $pages = [];

        foreach ($current as $key => $metrics) {
            $vital = $metrics['vital'];

            $pages[$metrics['name']][$vital->value] = VitalSummary::fromTotals(
                $vital,
                $metrics['totals'],
                $previous[$key] ?? null,
            );
        }

        $rows = [];

        foreach ($pages as $name => $vitals) {
            $rows[] = WebVitalPageRow::make((string) $name, $vitals);
        }

        foreach ($this->pagesWithoutMeasurements(array_keys($pages)) as $name) {
            $rows[] = WebVitalPageRow::withoutData($name);
        }

        $rows = WebVitalPageRow::sorted($rows);

        // Eine Zeile über der Grenze ist der Beleg, dass es mehr gibt. Geprüft
        // wird **nach** dem Sortieren: abgeschnitten werden soll das, was am
        // wenigsten wehtut, und nicht das, was die Datenbank zuletzt lieferte.
        $truncated = count($rows) > self::GROUP_LIMIT;
        $rows = array_slice($rows, 0, self::GROUP_LIMIT);

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        // Eine Seitenzahl jenseits des Endes ist kein Fehler, sondern ein Link,
        // der älter ist als die Daten. Er zeigt die letzte Seite.
        $page = min(max(1, $page), $lastPage);

        return new WebVitalOverviewResult(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            $total,
            $page,
            self::PER_PAGE,
            $truncated,
        );
    }

    /**
     * Abfrage 1: alle Messwerte des gewählten Zeitraums, gruppiert nach Seite und
     * Messwert.
     *
     * @return array<string, array{name: string, vital: WebVital, totals: array<string, mixed>}>
     */
    private function currentMetrics(): array
    {
        $rows = $this->grouped($this->filter->apply(WebVitalAggregate::query(), 'window_start'));

        $metrics = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $vital = WebVital::tryFrom((string) ($row['vital'] ?? ''));

            // Eine Zeile zu einem Messwert, den es nicht mehr gibt, wird
            // übergangen statt als unbenannte Spalte durchgereicht.
            if ($vital === null) {
                continue;
            }

            $metrics[self::key($name, $vital)] = [
                'name' => $name,
                'vital' => $vital,
                'totals' => $row,
            ];
        }

        return $metrics;
    }

    /**
     * Abfrage 2: derselbe Schnitt über den davorliegenden Zeitraum.
     *
     * Eingeschränkt auf die Seiten, die im gewählten Zeitraum vorkommen: für
     * alles andere gibt es keine Zeile, mit der zu vergleichen wäre.
     *
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>>
     */
    private function previousMetrics(array $names): array
    {
        [$from, $to] = $this->previousRange();

        $query = WebVitalAggregate::query()
            ->whereIn('project_id', $this->filter->projectIds())
            // Der Filter löst nur seinen **eigenen** Zeitraum auf; Projekte und
            // Umgebung kommen weiterhin aus ihm, die Grenzen hier sind die
            // verschobenen. Oben offen, damit das Fenster, in dem der gewählte
            // Zeitraum beginnt, nicht in beiden Hälften zählt.
            ->where('window_start', '>=', $from)
            ->where('window_start', '<', $to)
            ->whereIn('name', $names);

        if ($this->filter->environment !== null) {
            $query->where('environment', $this->filter->environment);
        }

        $metrics = [];

        foreach ($this->grouped($query) as $row) {
            $vital = WebVital::tryFrom((string) ($row['vital'] ?? ''));

            if ($vital === null) {
                continue;
            }

            $metrics[self::key((string) ($row['name'] ?? ''), $vital)] = $row;
        }

        return $metrics;
    }

    /**
     * Abfrage 3: Seiten mit Ladevorgängen, aber ohne einen einzigen Messwert.
     *
     * Gelesen aus der Vorberechnung der Antwortzeiten, weil dort **jede**
     * gemessene Seite steht — auch die, deren SDK keine Browser-Messwerte
     * schickt. Genau diese sollen gefunden werden.
     *
     * @param  list<string>  $measured  Seiten, für die es Messwerte gibt.
     * @return list<string>
     */
    private function pagesWithoutMeasurements(array $measured): array
    {
        $query = $this->filter
            ->apply(TransactionAggregate::query(), 'window_start')
            ->whereIn('op', self::BROWSER_OPS);

        $this->applySearch($query);

        if ($measured !== []) {
            $query->whereNotIn('name', $measured);
        }

        $rows = $query
            ->select('name')
            ->selectRaw('sum(transaction_count) as measured_count')
            ->groupBy('name')
            // Greift die Obergrenze, sollen die verkehrsreichsten Seiten übrig
            // bleiben; der Name als zweiter Schlüssel, damit derselbe Aufruf
            // denselben Ausschnitt liefert.
            ->orderByDesc('measured_count')
            ->orderBy('name')
            ->limit(self::GROUP_LIMIT + 1)
            ->toBase()
            ->get();

        return $rows->map(static fn (object $row): string => (string) $row->name)->all();
    }

    /**
     * Der gemeinsame Bau der beiden Messwert-Abfragen: gruppieren, Verteilung
     * zusammenlegen, abschneiden.
     *
     * Die Obergrenze steht auf dem Produkt aus Seiten und Messwerten, nicht auf
     * den Seiten allein — sonst schnitte sie bei sechs Messwerten schon nach
     * dreiundachtzig Seiten ab.
     *
     * @param  Builder<WebVitalAggregate>  $query
     * @return list<array<string, mixed>>
     */
    private function grouped(Builder $query): array
    {
        $this->applySearch($query);

        $selects = array_merge(
            [
                'sum(measurement_count) as measurement_count',
                'sum(good_count) as good_count',
                'sum(needs_improvement_count) as needs_improvement_count',
                'sum(poor_count) as poor_count',
                'sum(value_sum) as value_sum',
                'min(value_min) as value_min',
                'max(value_max) as value_max',
            ],
            VitalHistogram::sumExpressions(),
        );

        $rows = $query
            ->select('name', 'vital')
            ->selectRaw(implode(', ', $selects))
            ->groupBy('name', 'vital')
            ->orderByDesc('measurement_count')
            ->orderBy('name')
            ->orderBy('vital')
            ->limit((self::GROUP_LIMIT + 1) * count(WebVital::cases()))
            // An den Zeilen ist nichts, was ein Model beisteuern könnte: sie
            // tragen Summen und keine Spalten.
            ->toBase()
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $grouped[] = $values;
        }

        return $grouped;
    }

    /**
     * Die Suche — ein Teil des Seitennamens, mehr nicht.
     *
     * Bewusst nicht die Suchsyntax der Performance-Übersicht
     * ({@see TransactionSearch}): die kennt Schlüssel
     * wie `op:`, und die gibt es hier nicht — eine Seite hat keine Vorgangsart,
     * die man auswählen könnte. Eine Syntax anzubieten, von der die Hälfte ins
     * Leere greift, wäre schlechter als ein einfaches Feld.
     *
     * @param  Builder<WebVitalAggregate>|Builder<TransactionAggregate>  $query
     */
    private function applySearch(Builder $query): void
    {
        if ($this->search === '') {
            return;
        }

        // Die Platzhalter maskiert, damit ein `%` in der Eingabe ein Prozent
        // sucht und nicht alles trifft.
        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $this->search);

        $query->where('name', 'like', '%'.$needle.'%');
    }

    /**
     * Der Zeitraum davor: gleich lang, unmittelbar davor — wie bei den
     * Antwortzeiten.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function previousRange(): array
    {
        $from = $this->filter->fromUtc();
        $seconds = max(1, $this->filter->toUtc()->getTimestamp() - $from->getTimestamp());

        return [$from->subSeconds($seconds), $from];
    }

    /**
     * Der Schlüssel, unter dem die beiden Messwert-Abfragen zueinanderfinden.
     *
     * Ein Nullbyte als Trennzeichen, weil es das einzige Zeichen ist, das in
     * einem Seitennamen nicht vorkommen kann.
     */
    private static function key(string $name, WebVital $vital): string
    {
        return $name."\0".$vital->value;
    }
}
