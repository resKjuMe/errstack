<?php

namespace App\Support\Performance\Trends;

use App\Models\Project;
use App\Models\TransactionAggregate;
use App\Support\Performance\DurationHistogram;
use App\Support\Performance\TransactionOverview;
use Carbon\CarbonImmutable;

/**
 * Die Verläufe, auf denen die Bruchpunkt-Suche arbeitet: je Transaktion eine
 * Reihe von Stundenfenstern mit Anzahl und Verteilung.
 *
 * **Zwei Abfragen je Projekt und Umgebung, unabhängig von der Datenmenge** —
 * dieselbe Zusage wie in der Übersicht ({@see TransactionOverview}) und aus
 * demselben Grund: der Durchlauf findet regelmäßig für alle Projekte statt, und
 * eine Abfrage je Transaktion wären bei zweihundert Transaktionen zweihundert
 * Umläufe zur Datenbank in jeder Runde.
 *
 *   1. Welche Transaktionen überhaupt genug Verkehr haben, um etwas belegen zu
 *      können — die verkehrsreichsten zuerst.
 *   2. Für genau diese der Stundenverlauf.
 *
 * **Stunden und nicht Minuten.** Die Vorberechnung liegt in Minutenfenstern; über
 * eine Woche wären das 10.080 Zeilen je Transaktion, und ein einzelnes
 * Minutenfenster trägt selten genug Messungen, um ein p95 zu ergeben, das etwas
 * bedeutet. Die Stunde ist zugleich die Genauigkeit, mit der ein Bruch einer
 * Auslieferung zugeordnet werden kann ({@see TrendCause}) — feiner zu rechnen
 * hieße, eine Genauigkeit zu behaupten, die danach niemand nutzt.
 */
final class TrendSeries
{
    /**
     * Wie weit zurück gerechnet wird.
     *
     * Eine Woche. Kürzer wäre zu wenig: der Bruch braucht auf beiden Seiten
     * genug Stunden, und eine Verschlechterung von gestern hätte in einem
     * 24-Stunden-Fenster nur den halben Beleg. Deutlich länger wäre teuer,
     * ohne mehr zu finden — ein Bruch, der drei Wochen alt ist und niemandem
     * aufgefallen ist, ist inzwischen der Normalzustand und wird nach dieser
     * Rechnung auch nicht mehr als Bruch erkannt. Das ist keine Lücke, sondern
     * die Grenze der Aussage: „ist umgeschlagen" hat ein Verfallsdatum.
     */
    public const LOOKBACK_HOURS = 168;

    /**
     * Höchstens so viele Transaktionen je Projekt und Umgebung.
     *
     * Dieselbe Überlegung wie bei der Obergrenze der Übersicht
     * ({@see TransactionOverview::GROUP_LIMIT}): eine Anwendung, die ihre
     * Transaktionsnamen nicht im Griff hat, erzeugt Zehntausende Gruppen. Der
     * Durchlauf nimmt die verkehrsreichsten — sie sind die, deren
     * Verschlechterung jemanden trifft.
     */
    public const TRANSACTION_LIMIT = 200;

    /**
     * Die Verläufe einer Umgebung, nach Transaktion.
     *
     * @return list<array{name: string, op: string, windows: list<TrendWindow>}>
     */
    public static function forProject(Project $project, string $environment, CarbonImmutable $now): array
    {
        // Bis zum Anfang der laufenden Stunde: das angebrochene Fenster ist erst
        // teilweise gefüllt, und seine Anzahl wäre ein Einbruch, der keiner ist.
        $to = $now->utc()->startOfHour();
        $from = $to->subHours(self::LOOKBACK_HOURS);

        $busiest = self::busiest($project, $environment, $from, $to);

        if ($busiest === []) {
            return [];
        }

        $windows = self::windows(
            $project,
            $environment,
            $from,
            $to,
            array_values(array_unique(array_column($busiest, 'name'))),
        );

        $series = [];

        foreach ($busiest as $key => $transaction) {
            if (! isset($windows[$key])) {
                continue;
            }

            $series[] = [
                'name' => $transaction['name'],
                'op' => $transaction['op'],
                'windows' => $windows[$key],
            ];
        }

        return $series;
    }

    /**
     * Abfrage 1: die verkehrsreichsten Transaktionen des Zeitraums.
     *
     * Die Untergrenze steht schon hier und nicht erst in der Auswertung: eine
     * Transaktion, die im ganzen Zeitraum weniger Messungen hat, als für **eine**
     * Seite gebraucht werden ({@see BreakpointScan::MINIMUM_SIDE_SAMPLES}), kann
     * keinen Bruch belegen. Sie erst zu laden und dann zu verwerfen wäre der
     * teure Weg zu demselben Ergebnis.
     *
     * @return array<string, array{name: string, op: string}>
     */
    private static function busiest(
        Project $project,
        string $environment,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $rows = TransactionAggregate::query()
            ->where('project_id', $project->id)
            ->where('environment', $environment)
            ->where('window_start', '>=', $from)
            ->where('window_start', '<', $to)
            ->select('name', 'op')
            ->selectRaw('sum(transaction_count) as measured_count')
            ->groupBy('name', 'op')
            ->havingRaw('sum(transaction_count) >= ?', [2 * BreakpointScan::MINIMUM_SIDE_SAMPLES])
            ->orderByDesc('measured_count')
            ->orderBy('name')
            ->orderBy('op')
            ->limit(self::TRANSACTION_LIMIT)
            ->toBase()
            ->get();

        $busiest = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $name = (string) ($values['name'] ?? '');
            $op = (string) ($values['op'] ?? '');

            $busiest[self::key($name, $op)] = ['name' => $name, 'op' => $op];
        }

        return $busiest;
    }

    /**
     * Abfrage 2: der Stundenverlauf der ausgewählten Transaktionen.
     *
     * Gerastert wird über den **Text** des Zeitstempels (`substr(window_start,
     * 1, 13)`) — die einzige Schreibweise, die in MySQL und SQLite dasselbe tut;
     * `date_format` gibt es nur in der einen, `strftime` nur in der anderen. Die
     * Zusammenlegung der Verteilungen geschieht dabei in der Datenbank
     * ({@see DurationHistogram::sumExpressions()}): aus 10.080 Minutenfenstern je
     * Transaktion werden 168 Zeilen.
     *
     * Stunden ohne Messung fehlen und werden **nicht** aufgefüllt. Eine Null
     * wäre hier keine Auskunft, sondern eine Behauptung: „in dieser Stunde war
     * die Antwortzeit null" — die Suche würde daraus einen Bruch lesen, wo
     * schlicht Betriebsruhe war.
     *
     * @param  list<string>  $names
     * @return array<string, list<TrendWindow>>
     */
    private static function windows(
        Project $project,
        string $environment,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $names,
    ): array {
        $rows = TransactionAggregate::query()
            ->where('project_id', $project->id)
            ->where('environment', $environment)
            ->where('window_start', '>=', $from)
            ->where('window_start', '<', $to)
            ->whereIn('name', $names)
            ->select('name', 'op')
            ->selectRaw('substr(window_start, 1, 13) as window_key')
            ->selectRaw(implode(', ', array_merge(
                ['sum(transaction_count) as measured_count'],
                DurationHistogram::sumExpressions(),
            )))
            ->groupBy('name', 'op', 'window_key')
            ->orderBy('window_key')
            ->toBase()
            ->get();

        $windows = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $key = self::key((string) ($values['name'] ?? ''), (string) ($values['op'] ?? ''));

            $windows[$key][] = new TrendWindow(
                // Ausdrücklich als UTC gelesen: die Kennung kommt aus einer
                // Spalte in UTC, und `parse()` ohne Zone nähme die der
                // Anwendung — der Bruchpunkt läge um Stunden daneben und sähe
                // dabei völlig plausibel aus.
                at: CarbonImmutable::parse(((string) $values['window_key']).':00:00', 'UTC'),
                count: (int) ($values['measured_count'] ?? 0),
                histogram: DurationHistogram::fromRowSums($values),
            );
        }

        return $windows;
    }

    /**
     * Der Schlüssel, unter dem die beiden Abfragen zueinanderfinden — ein
     * Nullbyte als Trennzeichen, wie in der Übersicht: mit einem Punkt fielen
     * `("a", "b.c")` und `("a.b", "c")` zusammen.
     */
    private static function key(string $name, string $op): string
    {
        return $name."\0".$op;
    }
}
