<?php

namespace App\Support\Performance;

use App\Enums\TransactionSort;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\TransactionUserAggregate;
use App\Support\Filters\GlobalFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Abfrage hinter der Performance-Übersicht: aus Filter, Suche und
 * Sortierung werden die fertigen Zeilen.
 *
 * **Die Zusage dieser Klasse ist eine feste Zahl an Abfragen, unabhängig von der
 * Datenmenge.** Genau drei, und keine davon berührt die Einzelmessungen:
 *
 *   1. die Kennzahlen des gewählten Zeitraums aus `transaction_aggregates`,
 *   2. dieselben Kennzahlen des Vorzeitraums, nur so weit für den Trend nötig,
 *   3. die Nutzerzahlen aus `transaction_user_aggregates`.
 *
 * Daran hängt die Aussage „auch bei einer Million Transaktionen unter einer
 * Sekunde". Sie hält nur, solange zwei Dinge stimmen: die Zahl der Abfragen
 * wächst nicht mit der Datenmenge, und keine Abfrage liefert eine Zeile je
 * Messung. Beides ist geprüft (siehe `PerformanceOverviewTest`) — eine vierte
 * Abfrage, und sei es nur für die Suche, wäre eine zu viel.
 *
 * Der Kern ist Punkt 1: die Verteilungen der Zeitfenster werden **in der
 * Datenbank** zusammengelegt. Für jede der 31 Klassen
 * ({@see DurationHistogram::MAX_BUCKET}) steht eine Summe im `SELECT`; heraus
 * kommt eine Zeile je Transaktionsname, aus der sich jedes Perzentil lesen
 * lässt. Die Alternative — alle Fenster laden und in PHP addieren — wäre bei
 * einem Monat und tausend Namen ein Ergebnis von 44 Millionen Zeilen für eine
 * Seite mit fünfundzwanzig.
 *
 * Was danach in PHP passiert (Perzentile, Trend, Sortierung, Blättern), rechnet
 * über **eine Zeile je Transaktionsname**, nicht je Messung. Die Zahl der Namen
 * ist die Größe einer Anwendung und nicht die ihres Verkehrs.
 */
final class TransactionOverview
{
    /**
     * Zeilen je Seite.
     */
    public const PER_PAGE = 25;

    /**
     * Wie viele Transaktionen überhaupt zusammengetragen werden.
     *
     * Eine Anwendung, die ihre Namen nicht im Griff hat (`/users/4711` als
     * eigener „Name" je Nutzer), erzeugt Zehntausende Gruppen. Die Grenze
     * schneidet dann bei den verkehrsreichsten ab und sagt es der Oberfläche,
     * statt eine Seite zu bauen, an der minutenlang gerechnet wird. Ehrlich
     * abgeschnitten ist besser als vollständig und unbenutzbar.
     */
    public const GROUP_LIMIT = 500;

    public function __construct(
        private readonly GlobalFilter $filter,
        private readonly TransactionSearch $search,
        private readonly TransactionSort $sort,
        private readonly bool $descending,
    ) {}

    /**
     * Die angeforderte Seite der Übersicht.
     */
    public function page(int $page): TransactionOverviewResult
    {
        $current = $this->currentMetrics();

        // Ohne Messungen im Zeitraum gibt es nichts zu vergleichen und niemanden
        // zu zählen — die beiden folgenden Abfragen entfallen. Das ist kein
        // Bruch der Zusage von oben: sie gilt für die Datenmenge, nicht für den
        // leeren Fall.
        if ($current === []) {
            return new TransactionOverviewResult([], 0, 1, self::PER_PAGE, false);
        }

        // Eine Zeile über der Grenze ist der Beleg, dass es mehr gibt.
        $truncated = count($current) > self::GROUP_LIMIT;
        $current = array_slice($current, 0, self::GROUP_LIMIT, true);

        $names = array_values(array_unique(array_map(
            fn (array $metrics): string => $metrics['name'],
            $current,
        )));

        $previous = $this->previousMetrics($names);
        $users = $this->userCounts($names);
        $minutes = $this->minutes();

        $rows = [];

        foreach ($current as $key => $metrics) {
            $before = $previous[$key] ?? ['count' => 0, 'histogram' => DurationHistogram::empty()];
            $counted = $users[$key] ?? ['users' => 0, 'miserable' => 0];
            $histogram = $metrics['histogram'];

            $rows[] = new TransactionOverviewRow(
                name: $metrics['name'],
                op: $metrics['op'],
                transactionCount: $metrics['count'],
                extrapolatedCount: $metrics['extrapolated'],
                failureCount: $metrics['failures'],
                durationSumUs: $metrics['sumUs'],
                minUs: $metrics['minUs'],
                maxUs: $metrics['maxUs'],
                histogram: $histogram,
                users: $counted['users'],
                miserableUsers: $counted['miserable'],
                trend: TransactionTrend::between(
                    $histogram->percentile(0.95),
                    $metrics['count'],
                    $before['histogram']->percentile(0.95),
                    $before['count'],
                ),
                minutes: $minutes,
            );
        }

        $rows = TransactionOverviewRow::sorted($rows, $this->sort, $this->descending);

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        // Eine Seitenzahl jenseits des Endes ist kein Fehler, sondern ein Link,
        // der älter ist als die Daten. Er zeigt die letzte Seite.
        $page = min(max(1, $page), $lastPage);

        return new TransactionOverviewResult(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            $total,
            $page,
            self::PER_PAGE,
            $truncated,
        );
    }

    /**
     * Abfrage 1: alle Kennzahlen des gewählten Zeitraums, gruppiert nach
     * Transaktionsname und Operation.
     *
     * @return array<string, array{name: string, op: string, count: int, extrapolated: float, failures: int, sumUs: int, minUs: int|null, maxUs: int|null, histogram: DurationHistogram}>
     */
    private function currentMetrics(): array
    {
        $rows = $this->grouped(
            $this->filter->apply(TransactionAggregate::query(), 'window_start'),
            [
                'sum(extrapolated_count) as extrapolated_count',
                'sum(failure_count) as failure_count',
                'sum(duration_sum_us) as duration_sum_us',
                'min(duration_min_us) as duration_min_us',
                'max(duration_max_us) as duration_max_us',
            ],
        );

        $metrics = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $op = (string) ($row['op'] ?? '');

            $metrics[self::key($name, $op)] = [
                'name' => $name,
                'op' => $op,
                'count' => (int) ($row['measured_count'] ?? 0),
                'extrapolated' => (float) ($row['extrapolated_count'] ?? 0),
                'failures' => (int) ($row['failure_count'] ?? 0),
                'sumUs' => (int) ($row['duration_sum_us'] ?? 0),
                'minUs' => isset($row['duration_min_us']) ? (int) $row['duration_min_us'] : null,
                'maxUs' => isset($row['duration_max_us']) ? (int) $row['duration_max_us'] : null,
                'histogram' => DurationHistogram::fromRowSums($row),
            ];
        }

        return $metrics;
    }

    /**
     * Abfrage 2: derselbe Schnitt über den davorliegenden Zeitraum — aber nur
     * das, was der Trend braucht (Verteilung für das p95 und die Zahl der
     * Messungen dahinter).
     *
     * Eingeschränkt auf die Namen, die im gewählten Zeitraum vorkommen: für
     * alles andere gibt es keine Zeile, mit der zu vergleichen wäre. Das hält
     * die Abfrage klein und trifft die Vergleichswerte, die tatsächlich
     * gebraucht werden — eine Transaktion, die es nur früher gab, gehört nicht
     * in eine Übersicht des gewählten Zeitraums.
     *
     * @param  list<string>  $names
     * @return array<string, array{count: int, histogram: DurationHistogram}>
     */
    private function previousMetrics(array $names): array
    {
        [$from, $to] = $this->previousRange();

        $query = TransactionAggregate::query()
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

        foreach ($this->grouped($query, []) as $row) {
            $key = self::key((string) ($row['name'] ?? ''), (string) ($row['op'] ?? ''));

            $metrics[$key] = [
                'count' => (int) ($row['measured_count'] ?? 0),
                'histogram' => DurationHistogram::fromRowSums($row),
            ];
        }

        return $metrics;
    }

    /**
     * Abfrage 3: wie viele Nutzer eine Transaktion hatte und wie vielen davon
     * sie zu langsam war.
     *
     * `COUNT(DISTINCT user_key)` und nicht eine Summe: ein Nutzer, der in zehn
     * Minuten des Zeitraums auftaucht, hat zehn Zeilen und ist trotzdem ein
     * Nutzer. Genau deshalb steht in der Vorberechnung eine Zeile je Nutzer und
     * nicht nur ein Zähler.
     *
     * @param  list<string>  $names
     * @return array<string, array{users: int, miserable: int}>
     */
    private function userCounts(array $names): array
    {
        $rows = $this->filter
            ->apply(TransactionUserAggregate::query(), 'window_start')
            ->select('name', 'op')
            ->selectRaw('count(distinct user_key) as user_count')
            // Unzufrieden ist ein Nutzer, sobald **eine** seiner Messungen über
            // der Schwelle lag. Nicht der Anteil seiner Aufrufe: wer einmal
            // vierzig Sekunden gewartet hat, erinnert sich daran und nicht an die
            // neunundneunzig schnellen davor.
            ->selectRaw('count(distinct case when miserable_count > 0 then user_key end) as miserable_user_count')
            ->whereIn('name', $names)
            ->groupBy('name', 'op')
            ->toBase()
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $key = self::key((string) ($values['name'] ?? ''), (string) ($values['op'] ?? ''));

            $counts[$key] = [
                'users' => (int) ($values['user_count'] ?? 0),
                'miserable' => (int) ($values['miserable_user_count'] ?? 0),
            ];
        }

        return $counts;
    }

    /**
     * Der gemeinsame Bau der beiden Aggregat-Abfragen: gruppieren, Verteilung
     * zusammenlegen, abschneiden.
     *
     * @param  Builder<TransactionAggregate>  $query
     * @param  list<string>  $aggregates  zusätzliche Summen neben der Anzahl
     * @return list<array<string, mixed>>
     */
    private function grouped(Builder $query, array $aggregates): array
    {
        $this->search->apply($query);

        $selects = array_merge(
            ['sum(transaction_count) as measured_count'],
            $aggregates,
            DurationHistogram::sumExpressions(),
        );

        $rows = $query
            ->select('name', 'op')
            ->selectRaw(implode(', ', $selects))
            ->groupBy('name', 'op')
            // Greift die Obergrenze, sollen die verkehrsreichsten Transaktionen
            // übrig bleiben — sie sind die, wegen derer jemand die Seite öffnet.
            // Name und Operation als zweiter Schlüssel, damit derselbe Aufruf
            // denselben Ausschnitt liefert und nicht die Reihenfolge der
            // Datenbank entscheidet.
            ->orderByDesc('measured_count')
            ->orderBy('name')
            ->orderBy('op')
            ->limit(self::GROUP_LIMIT + 1)
            // An den Zeilen ist nichts, was ein Model beisteuern könnte: sie
            // tragen Summen und keine Spalten. Als Models geladen bekämen sie
            // eine Kennung, die es nicht gibt, und Umwandlungen für Felder, die
            // nicht abgefragt wurden.
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
     * Der Zeitraum davor: gleich lang, unmittelbar davor.
     *
     * Gleich lang, damit die Zahlen vergleichbar sind — ein Trend gegen einen
     * kürzeren Vorzeitraum verglichen wäre keiner.
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
     * Die Länge des Zeitraums in Minuten — der Nenner des Durchsatzes.
     *
     * Aufgerundet und mindestens eine: ein Zeitraum von dreißig Sekunden mit
     * zehn Aufrufen ergibt sonst zwanzig Aufrufe je Minute, obwohl niemand
     * gesehen hat, was in der zweiten Hälfte der Minute geschah.
     */
    private function minutes(): int
    {
        $seconds = $this->filter->toUtc()->getTimestamp() - $this->filter->fromUtc()->getTimestamp();

        return max(1, (int) ceil($seconds / 60));
    }

    /**
     * Der Schlüssel, unter dem die drei Abfragen zueinanderfinden.
     *
     * Ein Nullbyte als Trennzeichen, weil es das einzige Zeichen ist, das in
     * einem Transaktionsnamen nicht vorkommen kann ({@see Transaction::NAME_LIMIT}
     * begrenzt die Länge, nicht den Inhalt) — mit einem Punkt fielen
     * `("a", "b.c")` und `("a.b", "c")` zusammen.
     */
    private static function key(string $name, string $op): string
    {
        return $name."\0".$op;
    }
}
