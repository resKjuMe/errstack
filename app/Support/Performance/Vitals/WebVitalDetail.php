<?php

namespace App\Support\Performance\Vitals;

use App\Enums\CountPeriod;
use App\Enums\WebVital;
use App\Models\Transaction;
use App\Models\WebVitalAggregate;
use App\Support\Filters\GlobalFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Das Ladeerlebnis einer einzelnen Seite: alle Messwerte, der Verlauf des
 * gewählten und die Aufschlüsselung nach Gerät, Browser und Land.
 *
 * **Die Seite besteht aus zwei Arten von Zahlen, und der Unterschied ist ihr
 * wichtigster Gestaltungspunkt** — dieselbe Trennung wie bei der
 * Transaktions-Detailseite (PF3), aus denselben Gründen:
 *
 * Die *Kennzahlen* — Perzentile, Bewertung, Verteilung, Verlauf — kommen
 * ausschließlich aus der Vorberechnung ({@see WebVitalAggregate}). Sie sind
 * vollständig: sie berücksichtigen jeden gemeldeten Ladevorgang des Zeitraums
 * und kosten gleich viel, ob dahinter tausend stehen oder zehn Millionen.
 *
 * Die *Aufschlüsselungen* nach Gerät, Browser und Land sind aus der
 * Vorberechnung nicht zu bekommen — diese drei Merkmale stehen bewusst nicht in
 * ihrem Schlüssel, weil sie die Zeilenzahl vervielfachen würden. Sie beruhen
 * deshalb auf einer **begrenzten Stichprobe** von {@see SAMPLE_LIMIT}
 * Einzelmessungen. Das ist eine bewusste Grenze: „liegt es am Handy" ist eine
 * Frage nach Anteilen, und Anteile lassen sich aus einer Stichprobe schätzen.
 * Die Seite sagt die Größe der Stichprobe dazu, statt sie zu verschweigen.
 *
 * **Die Zahl der Abfragen ist fest** — vier, unabhängig von der Datenmenge:
 *
 *   1. alle Messwerte des Zeitraums, je einer eine Zeile,
 *   2. dieselben Summen des Vorzeitraums für den Trend,
 *   3. der Verlauf des **gewählten** Messwerts, in Stunden oder Tagen gerastert,
 *   4. die Stichprobe der Einzelmessungen für die Aufschlüsselungen.
 *
 * Dass Verlauf und Aufschlüsselung nur für **einen** Messwert gelten, ist keine
 * Sparmaßnahme, sondern die richtige Darstellung: sechs Verlaufsgrafiken
 * nebeneinander beantworten keine Frage, die eine nicht schon beantwortet, und
 * die Frage „woran liegt mein schlechtes LCP" richtet sich immer an genau einen
 * Messwert.
 */
final class WebVitalDetail
{
    /**
     * So viele Einzelmessungen werden für die Aufschlüsselungen gelesen.
     *
     * Fünfhundert wie bei den Antwortzeiten (PF3), und aus demselben Grund:
     * genug, dass ein Anteil von wenigen Prozent noch sichtbar wird, und wenig
     * genug, dass die Abfrage eine Seite nicht aufhält. Anders als dort werden
     * keine Einzelschritte nachgeladen — die Grenze ist hier großzügiger, als
     * sie sein müsste.
     */
    public const SAMPLE_LIMIT = 500;

    /**
     * Ab dieser Länge des Zeitraums wird der Verlauf in Tagen gezeichnet.
     *
     * Dieselbe Grenze wie überall sonst: 72 Balken sind das, was in einer Grafik
     * dieser Breite noch etwas zeigt.
     */
    private const HOURLY_LIMIT_HOURS = 72;

    public function __construct(
        private readonly GlobalFilter $filter,
        private readonly string $name,
        private readonly WebVital $selected,
    ) {}

    public function result(): WebVitalDetailResult
    {
        $totals = $this->totals();
        $previous = $this->previousTotals();

        $summaries = [];

        foreach (WebVital::cases() as $vital) {
            $row = $totals[$vital->value] ?? null;

            $summaries[$vital->value] = $row === null
                ? VitalSummary::empty($vital)
                : VitalSummary::fromTotals($vital, $row, $previous[$vital->value] ?? null);
        }

        $sample = $this->sample();

        return new WebVitalDetailResult(
            name: $this->name,
            selected: $this->selected,
            summaries: $summaries,
            histogram: $summaries[$this->selected->value]->histogram->bars(),
            seriesPeriod: $this->period(),
            series: $this->series(),
            facets: $this->facets($sample),
            sampledTransactions: $sample->count(),
            sampleLimit: self::SAMPLE_LIMIT,
        );
    }

    /**
     * Abfrage 1: die Summen des gewählten Zeitraums, eine Zeile je Messwert.
     *
     * @return array<string, array<string, mixed>>
     */
    private function totals(): array
    {
        return $this->summed($this->aggregates());
    }

    /**
     * Abfrage 2: der Vorzeitraum, gleich lang und unmittelbar davor — nur so
     * weit, wie der Trend es braucht.
     *
     * @return array<string, array<string, mixed>>
     */
    private function previousTotals(): array
    {
        $from = $this->filter->fromUtc();
        $seconds = max(1, $this->filter->toUtc()->getTimestamp() - $from->getTimestamp());

        $query = WebVitalAggregate::query()
            ->whereIn('project_id', $this->filter->projectIds())
            ->where('name', $this->name)
            // Oben offen, damit das Fenster, in dem der gewählte Zeitraum
            // beginnt, nicht in beiden Hälften zählt.
            ->where('window_start', '>=', $from->subSeconds($seconds))
            ->where('window_start', '<', $from);

        if ($this->filter->environment !== null) {
            $query->where('environment', $this->filter->environment);
        }

        return $this->summed($query);
    }

    /**
     * Abfrage 3: der Verlauf des gewählten Messwerts.
     *
     * Gerastert wird über den **Text** des Zeitstempels (`substr(window_start,
     * 1, 13)` für die Stunde, `1, 10` für den Tag) — dieselbe Schreibweise wie
     * bei den Antwortzeiten und aus demselben Grund: sie ist die einzige, die in
     * MySQL und SQLite dasselbe tut.
     *
     * @return list<array{window: string, count: int, value: int|null, rating: string|null}>
     */
    private function series(): array
    {
        $length = $this->period() === CountPeriod::Hour ? 13 : 10;

        $rows = $this->aggregates()
            ->where('vital', $this->selected->value)
            ->selectRaw("substr(window_start, 1, {$length}) as window_key")
            ->selectRaw(implode(', ', array_merge(
                [
                    'sum(measurement_count) as measurement_count',
                    'sum(good_count) as good_count',
                    'sum(needs_improvement_count) as needs_improvement_count',
                    'sum(poor_count) as poor_count',
                    'sum(value_sum) as value_sum',
                ],
                VitalHistogram::sumExpressions(),
            )))
            ->groupBy('window_key')
            ->orderBy('window_key')
            ->toBase()
            ->get();

        $points = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            // Über dieselbe Zusammenfassung wie die Kennzahlen oben: ein Punkt
            // im Verlauf soll genauso bewertet und genauso zurechtgerückt werden
            // wie der Gesamtwert. Zwei Rechenwege für dieselbe Zahl wären zwei
            // Gelegenheiten, sich zu unterscheiden.
            $summary = VitalSummary::fromTotals($this->selected, $values);

            $points[] = [
                // Die Kennung des Fensters ausdrücklich als UTC gelesen: sie
                // kommt aus einer Spalte in UTC, und `parse()` ohne Zone nähme
                // die der Anwendung — der Verlauf wäre um Stunden verschoben und
                // sähe dabei völlig plausibel aus.
                'window' => CarbonImmutable::parse(
                    $this->period() === CountPeriod::Hour
                        ? ((string) $values['window_key']).':00:00'
                        : ((string) $values['window_key']).' 00:00:00',
                    'UTC',
                )->toIso8601String(),
                'count' => $summary->count,
                'value' => $summary->value,
                'rating' => $summary->rating?->value,
            ];
        }

        return $points;
    }

    /**
     * Abfrage 4: die Stichprobe der Einzelmessungen.
     *
     * Die neuesten zuerst, nicht die langsamsten: die Aufschlüsselung soll den
     * **jetzigen** Zustand zeigen. Eine Stichprobe aus den schlechtesten
     * Ladevorgängen fände in jedem Land ein schlechtes Ergebnis, weil sie nur
     * schlechte Ladevorgänge enthielte.
     *
     * @return Collection<int, Transaction>
     */
    private function sample(): Collection
    {
        return $this->filter
            ->apply(Transaction::query(), 'started_at')
            ->where('name', $this->name)
            // Nur die Ladevorgänge mit Messwerten. Ohne diese Bedingung
            // bestünde die Stichprobe aus fünfhundert Zeilen, von denen keine
            // etwas beiträgt, sobald unter demselben Namen auch serverseitig
            // gemessen wird.
            ->whereNotNull('measurements')
            ->select(['id', 'measurements', 'browser', 'device', 'country', 'started_at'])
            ->orderByDesc('started_at')
            ->limit(self::SAMPLE_LIMIT)
            ->get();
    }

    /**
     * Die Aufschlüsselung des gewählten Messwerts nach den drei Merkmalen, die
     * an einer Browser-Messung stehen.
     *
     * @param  Collection<int, Transaction>  $sample
     * @return list<VitalFacet>
     */
    private function facets(Collection $sample): array
    {
        if ($sample->isEmpty()) {
            return [];
        }

        $keys = [
            'device' => static fn (Transaction $transaction): ?string => $transaction->device,
            'browser' => static fn (Transaction $transaction): ?string => $transaction->browser,
            'country' => static fn (Transaction $transaction): ?string => $transaction->country,
        ];

        // Der Messwert wird **einmal** je Ladevorgang aus dem Feld-Baum gelesen
        // und nicht je Merkmal erneut: sonst würde dieselbe Deutung dreimal
        // gerechnet, bei fünfhundert Messungen also fünfzehnhundertmal.
        $measured = [];

        foreach ($sample as $transaction) {
            $reading = VitalReading::all($transaction->measurements)[$this->selected->value] ?? null;

            if ($reading !== null) {
                $measured[] = [$transaction, $reading->value];
            }
        }

        $facets = [];

        foreach ($keys as $key => $read) {
            /** @var array<string, list<int>> $values */
            $values = [];

            foreach ($measured as [$transaction, $value]) {
                $facetValue = $read($transaction);

                // Messungen ohne Angabe fallen weg statt unter „unbekannt" zu
                // laufen: eine Sammelzeile aus allem, was das SDK nicht
                // mitgeschickt hat, sähe aus wie ein Wert und wäre keiner.
                if ($facetValue === null || $facetValue === '') {
                    continue;
                }

                $values[$facetValue][] = $value;
            }

            // Ein Merkmal mit genau einem Wert sagt nichts — es ist der
            // Regelfall bei einer Anwendung, die nur in einem Land benutzt wird.
            if (count($values) < 2) {
                continue;
            }

            $facets[] = VitalFacet::build($key, $this->selected, $values);
        }

        return $facets;
    }

    /**
     * Legt die Zeitfenster einer Abfrage je Messwert zusammen.
     *
     * @param  Builder<WebVitalAggregate>  $query
     * @return array<string, array<string, mixed>>
     */
    private function summed(Builder $query): array
    {
        $rows = $query
            ->select('vital')
            ->selectRaw(implode(', ', array_merge(
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
            )))
            ->groupBy('vital')
            ->toBase()
            ->get();

        $summed = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $summed[(string) ($values['vital'] ?? '')] = $values;
        }

        return $summed;
    }

    /**
     * Die Vorberechnung dieser Seite im gewählten Zeitraum.
     *
     * @return Builder<WebVitalAggregate>
     */
    private function aggregates(): Builder
    {
        return $this->filter
            ->apply(WebVitalAggregate::query(), 'window_start')
            ->where('name', $this->name);
    }

    /**
     * Die Auflösung des Verlaufs: Stunden bei kurzen Zeiträumen, Tage bei
     * langen.
     */
    private function period(): CountPeriod
    {
        $hours = ($this->filter->toUtc()->getTimestamp() - $this->filter->fromUtc()->getTimestamp()) / 3600;

        return $hours <= self::HOURLY_LIMIT_HOURS ? CountPeriod::Hour : CountPeriod::Day;
    }
}
