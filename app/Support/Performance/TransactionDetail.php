<?php

namespace App\Support\Performance;

use App\Enums\CountPeriod;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\TransactionSpan;
use App\Models\TransactionUserAggregate;
use App\Support\Filters\GlobalFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Die Detailanalyse einer Transaktion: Verteilung, Verlauf, die größten
 * Zeitfresser, Beispielfälle, auffällige Merkmale und die Fehler dazu.
 *
 * **Die Seite besteht aus zwei Arten von Zahlen, und der Unterschied ist ihr
 * wichtigster Gestaltungspunkt.**
 *
 * Die *Kennzahlen* — Perzentile, Durchsatz, Fehlerrate, Verlauf — kommen wie in
 * der Übersicht ausschließlich aus den Vorberechnungen
 * ({@see TransactionAggregate}, {@see TransactionUserAggregate}). Sie sind
 * vollständig: sie berücksichtigen jede Messung des Zeitraums, und sie kosten
 * gleich viel, ob dahinter tausend Aufrufe stehen oder zehn Millionen.
 *
 * Die *Aufschlüsselungen* — welche Vorgangsart wie viel Zeit brauchte, welche
 * Version auffällt, welcher Aufruf sich ansehen lässt — sind aus den
 * Einzelmessungen nicht anders zu bekommen: die Vorberechnung kennt keine
 * Einzelschritte und weder Version noch Plattform. Sie beruhen deshalb auf einer
 * **begrenzten Stichprobe** von {@see self::SAMPLE_LIMIT} Messungen. Das ist
 * eine bewusste Grenze und keine Nachlässigkeit: „wo geht die Zeit hin" ist eine
 * Frage nach Anteilen, und Anteile lassen sich aus einer Stichprobe schätzen.
 * Die Seite sagt die Größe der Stichprobe dazu, statt sie zu verschweigen.
 *
 * **Die Zahl der Abfragen ist fest** — acht, unabhängig von der Datenmenge, und
 * keine liefert eine Zeile je Messung:
 *
 *   1. Kennzahlen des Zeitraums (eine Zeile),
 *   2. der Verlauf, in Stunden oder Tagen gerastert,
 *   3. dieselben Kennzahlen des Vorzeitraums für den Trend,
 *   4. die Nutzerzahlen,
 *   5. die Stichprobe der Einzelmessungen,
 *   6. ihre Einzelschritte, nach Vorgangsart zusammengelegt,
 *   7. das langsamste Beispiel je Vorgangsart,
 *   8. die Fehler, die unter diesem Transaktionsnamen gemeldet wurden.
 */
final class TransactionDetail
{
    /**
     * So viele Einzelmessungen werden für die Aufschlüsselungen gelesen.
     *
     * Fünfhundert, weil zwei Dinge zusammenkommen müssen: genug, dass ein
     * Anteil von wenigen Prozent noch sichtbar wird, und wenig genug, dass die
     * Schritte dieser Messungen (bei hundert Schritten je Aufruf sind das
     * 50.000 Zeilen) eine Abfrage bleiben, die eine Seite nicht aufhält.
     */
    public const SAMPLE_LIMIT = 500;

    /**
     * So viele Vorgangsarten zeigt die Aufschlüsselung.
     *
     * Was danach kommt, ist Rauschen — und die Frage „was ist der größte
     * Zeitfresser" ist nach den ersten Zeilen beantwortet.
     */
    public const SPAN_LIMIT = 8;

    /**
     * So viele Fehler werden zu einer Transaktion gezeigt.
     */
    public const ISSUE_LIMIT = 5;

    /**
     * Ab dieser Länge des Zeitraums wird der Verlauf in Tagen gezeichnet.
     *
     * Dieselbe Grenze wie bei den Verlaufsgrafiken der Fehlerliste: 72 Balken
     * sind das, was in einer Grafik dieser Breite noch etwas zeigt.
     */
    private const HOURLY_LIMIT_HOURS = 72;

    /**
     * Die Perzentil-Bereiche, für die je ein Beispielfall gesucht wird.
     *
     * Der Median als „so ist es normalerweise", das p95 und das p99 als „so ist
     * es, wenn es schiefgeht". Ohne den Median fehlte der Vergleich: ein
     * langsamer Aufruf allein sagt nicht, welcher seiner Schritte ungewöhnlich
     * ist.
     */
    private const SAMPLE_PERCENTILES = [0.5, 0.95, 0.99];

    public function __construct(
        private readonly GlobalFilter $filter,
        private readonly string $name,
        private readonly string $op,
    ) {}

    public function result(): TransactionDetailResult
    {
        $totals = $this->totals();
        $histogram = DurationHistogram::fromRowSums($totals);
        $users = $this->userCounts();
        $previous = $this->previousTotals();

        $summary = new TransactionOverviewRow(
            name: $this->name,
            op: $this->op,
            transactionCount: (int) ($totals['measured_count'] ?? 0),
            extrapolatedCount: (float) ($totals['extrapolated_count'] ?? 0),
            failureCount: (int) ($totals['failure_count'] ?? 0),
            durationSumUs: (int) ($totals['duration_sum_us'] ?? 0),
            minUs: isset($totals['duration_min_us']) ? (int) $totals['duration_min_us'] : null,
            maxUs: isset($totals['duration_max_us']) ? (int) $totals['duration_max_us'] : null,
            histogram: $histogram,
            users: $users['users'],
            miserableUsers: $users['miserable'],
            trend: TransactionTrend::between(
                $histogram->percentile(0.95),
                (int) ($totals['measured_count'] ?? 0),
                DurationHistogram::fromRowSums($previous)->percentile(0.95),
                (int) ($previous['measured_count'] ?? 0),
            ),
            minutes: $this->minutes(),
        );

        $sample = $this->sample();
        $spans = $this->spanBreakdown($sample);

        return new TransactionDetailResult(
            name: $this->name,
            op: $this->op,
            summary: $summary,
            histogram: self::histogramBars($histogram),
            seriesPeriod: $this->period()->value,
            series: $this->series(),
            spans: $spans,
            samples: $this->samples($sample),
            facets: $this->facets($sample),
            issues: $this->issues(),
            sampledTransactions: $sample->count(),
            sampleLimit: self::SAMPLE_LIMIT,
        );
    }

    /**
     * Abfrage 1: die Kennzahlen des gewählten Zeitraums, in einer Zeile.
     *
     * @return array<string, mixed>
     */
    private function totals(): array
    {
        $row = $this->aggregates()
            ->selectRaw(implode(', ', array_merge(
                [
                    'sum(transaction_count) as measured_count',
                    'sum(extrapolated_count) as extrapolated_count',
                    'sum(failure_count) as failure_count',
                    'sum(duration_sum_us) as duration_sum_us',
                    'min(duration_min_us) as duration_min_us',
                    'max(duration_max_us) as duration_max_us',
                ],
                DurationHistogram::sumExpressions(),
            )))
            ->toBase()
            ->first();

        return $row === null ? [] : (array) $row;
    }

    /**
     * Abfrage 2: der Verlauf.
     *
     * Gerastert wird über den **Text** des Zeitstempels (`substr(window_start,
     * 1, 13)` für die Stunde, `1, 10` für den Tag). Das sieht ungewöhnlich aus
     * und ist die einzige Schreibweise, die in MySQL und SQLite dasselbe tut:
     * `date_format` gibt es nur in der einen, `strftime` nur in der anderen, und
     * eine Fallunterscheidung nach Treiber wäre zwei Abfragen, von denen im
     * Betrieb immer nur eine gelesen wird.
     *
     * Die Vorberechnung liegt in Fenstern von einer Minute; ein Zeitraum von
     * 90 Tagen wären 129.600 Zeilen. Zusammengelegt in der Datenbank sind es
     * höchstens 90 (Tage) oder 72 (Stunden).
     *
     * @return list<array{window: string, count: int, p95Us: int|null, failureRate: float|null}>
     */
    private function series(): array
    {
        $length = $this->period() === CountPeriod::Hour ? 13 : 10;

        $rows = $this->aggregates()
            ->selectRaw("substr(window_start, 1, {$length}) as window_key")
            ->selectRaw(implode(', ', array_merge(
                [
                    'sum(transaction_count) as measured_count',
                    'sum(failure_count) as failure_count',
                ],
                DurationHistogram::sumExpressions(),
            )))
            ->groupBy('window_key')
            ->orderBy('window_key')
            ->toBase()
            ->get();

        $points = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $count = (int) ($values['measured_count'] ?? 0);

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
                'count' => $count,
                'p95Us' => DurationHistogram::fromRowSums($values)->percentile(0.95),
                'failureRate' => $count === 0 ? null : ((int) ($values['failure_count'] ?? 0)) / $count,
            ];
        }

        return $points;
    }

    /**
     * Abfrage 3: der Vorzeitraum, gleich lang und unmittelbar davor — nur so
     * weit, wie der Trend es braucht.
     *
     * @return array<string, mixed>
     */
    private function previousTotals(): array
    {
        $from = $this->filter->fromUtc();
        $seconds = max(1, $this->filter->toUtc()->getTimestamp() - $from->getTimestamp());

        $query = TransactionAggregate::query()
            ->whereIn('project_id', $this->filter->projectIds())
            ->where('name', $this->name)
            ->where('op', $this->op)
            // Oben offen, damit das Fenster, in dem der gewählte Zeitraum
            // beginnt, nicht in beiden Hälften zählt.
            ->where('window_start', '>=', $from->subSeconds($seconds))
            ->where('window_start', '<', $from);

        if ($this->filter->environment !== null) {
            $query->where('environment', $this->filter->environment);
        }

        $row = $query
            ->selectRaw(implode(', ', array_merge(
                ['sum(transaction_count) as measured_count'],
                DurationHistogram::sumExpressions(),
            )))
            ->toBase()
            ->first();

        return $row === null ? [] : (array) $row;
    }

    /**
     * Abfrage 4: wie viele Nutzer diese Transaktion hatte und wie vielen davon
     * sie zu langsam war.
     *
     * @return array{users: int, miserable: int}
     */
    private function userCounts(): array
    {
        $row = $this->filter
            ->apply(TransactionUserAggregate::query(), 'window_start')
            ->where('name', $this->name)
            ->where('op', $this->op)
            ->selectRaw('count(distinct user_key) as user_count')
            ->selectRaw('count(distinct case when miserable_count > 0 then user_key end) as miserable_user_count')
            ->toBase()
            ->first();

        /** @var array<string, mixed> $values */
        $values = $row === null ? [] : (array) $row;

        return [
            'users' => (int) ($values['user_count'] ?? 0),
            'miserable' => (int) ($values['miserable_user_count'] ?? 0),
        ];
    }

    /**
     * Abfrage 5: die Stichprobe der Einzelmessungen.
     *
     * Die jüngsten und nicht die langsamsten: die Aufschlüsselung soll zeigen,
     * wie sich die Zeit **im Regelfall** verteilt. Nähme man die langsamsten,
     * stünde dort die Verteilung der Ausreißer — interessant, aber eine andere
     * Frage, und für sie gibt es die Beispielfälle.
     *
     * @return Collection<int, Transaction>
     */
    private function sample(): Collection
    {
        return $this->filter
            ->apply(Transaction::query(), 'started_at')
            ->where('name', $this->name)
            ->where(function (Builder $query): void {
                // Die Vorberechnung führt „ohne Operation" als leere
                // Zeichenkette, die Einzelmessung als `null` — derselbe
                // Sachverhalt in zwei Schreibweisen, und die Detailseite muss
                // beide treffen.
                if ($this->op === '') {
                    $query->whereNull('op')->orWhere('op', '');

                    return;
                }

                $query->where('op', $this->op);
            })
            ->orderByDesc('started_at')
            ->limit(self::SAMPLE_LIMIT)
            ->get([
                'id', 'event_id', 'trace_id', 'duration_us', 'span_count',
                'release', 'environment', 'platform', 'started_at',
            ]);
    }

    /**
     * Abfragen 6 und 7: wohin die Zeit ging.
     *
     * Zusammengelegt wird über die Vorgangsart (`db.sql.query`, `http.client`,
     * `template.render`), weil das die Frage ist, die eine Antwort zulässt: „die
     * Datenbank" ist ein nächster Schritt, „diese eine von 4.000 Abfragen" ist
     * eine Liste.
     *
     * Die Schritte werden dabei **nicht** ins Verhältnis zur Dauer der
     * Transaktion gesetzt, sondern zueinander. Der Grund ist die
     * Verschachtelung: Schritte liegen ineinander, ihre Summe ist damit größer
     * als die Transaktion, und ein Anteil „an der Antwortzeit" wäre eine Zahl
     * über 100 %. Der Anteil an der Gesamtzeit aller Schritte beantwortet
     * dieselbe Frage ohne diese Falle.
     *
     * @param  Collection<int, Transaction>  $sample
     * @return list<SpanBreakdownRow>
     */
    private function spanBreakdown(Collection $sample): array
    {
        $ids = $sample->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $rows = TransactionSpan::query()
            ->whereIn('transaction_id', $ids)
            ->selectRaw("coalesce(op, '') as span_op")
            ->selectRaw('count(*) as span_count')
            ->selectRaw('sum(duration_us) as total_us')
            ->selectRaw('count(distinct transaction_id) as transaction_count')
            ->groupBy('span_op')
            ->orderByDesc('total_us')
            ->limit(self::SPAN_LIMIT)
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ops = [];
        $total = 0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $ops[] = $values;
            $total += (int) ($values['total_us'] ?? 0);
        }

        $examples = $this->spanExamples($ids, array_map(
            fn (array $row): string => (string) ($row['span_op'] ?? ''),
            $ops,
        ));

        $breakdown = [];

        foreach ($ops as $row) {
            $op = (string) ($row['span_op'] ?? '');

            $breakdown[] = new SpanBreakdownRow(
                op: $op,
                count: (int) ($row['span_count'] ?? 0),
                totalUs: (int) ($row['total_us'] ?? 0),
                transactions: (int) ($row['transaction_count'] ?? 0),
                example: $examples[$op] ?? null,
                breakdownTotalUs: $total,
            );
        }

        return $breakdown;
    }

    /**
     * Der langsamste Schritt je Vorgangsart, als Beleg.
     *
     * Der langsamste und nicht irgendeiner: „die Datenbank braucht 60 % der
     * Zeit" ist ein Befund, „und zwar diese Abfrage" ist die Arbeitsanweisung.
     *
     * @param  list<int>  $transactionIds
     * @param  list<string>  $ops
     * @return array<string, string>
     */
    private function spanExamples(array $transactionIds, array $ops): array
    {
        if ($ops === []) {
            return [];
        }

        $rows = TransactionSpan::query()
            ->whereIn('transaction_id', $transactionIds)
            ->whereIn(DB::raw("coalesce(op, '')"), $ops)
            ->whereNotNull('description')
            ->orderByDesc('duration_us')
            // Reichlich bemessen: gesucht ist der erste Treffer je Vorgangsart,
            // und die längsten Schritte können alle derselben angehören.
            ->limit(self::SPAN_LIMIT * 20)
            ->get(['op', 'description']);

        $examples = [];

        foreach ($rows as $row) {
            $op = $row->op ?? '';

            if (! isset($examples[$op]) && $row->description !== null) {
                $examples[$op] = $row->description;
            }
        }

        return $examples;
    }

    /**
     * Die Beispielfälle, je Perzentil-Bereich einer.
     *
     * Gewählt wird über den **Rang in der nach Dauer sortierten Stichprobe**:
     * für das p95 der Aufruf, unter dem 95 % der Stichprobe liegen. Damit
     * stammt der Fall zwangsläufig aus dem gemeinten Bereich.
     *
     * Der naheliegende Weg — die Schwelle aus der vollständigen Verteilung
     * nehmen und den ersten Aufruf darüber suchen — ist der falsche, und zwar
     * unauffällig: {@see DurationHistogram::percentile()} gibt bewusst die
     * **Obergrenze** der Klasse zurück, in der das Perzentil liegt. Bei
     * verdoppelnden Klassen ist das bis zum Doppelten des echten Werts, und der
     * erste Aufruf darüber ist dann nicht der aus dem p50-Bereich, sondern der
     * nächste Ausreißer. In einer Stichprobe aus lauter gleich schnellen
     * Aufrufen und einem langsamen liefe damit jeder Perzentil-Bereich auf
     * denselben langsamen Fall hinaus.
     *
     * @param  Collection<int, Transaction>  $sample
     * @return list<TransactionSample>
     */
    private function samples(Collection $sample): array
    {
        if ($sample->isEmpty()) {
            return [];
        }

        /** @var list<Transaction> $sorted */
        $sorted = $sample->sortBy('duration_us')->values()->all();

        $traceRoute = Route::has('traces.show');
        $samples = [];
        $seen = [];

        foreach (self::SAMPLE_PERCENTILES as $percentile) {
            $match = self::atPercentile($sorted, $percentile);

            // Dieselbe Messung nicht zweimal: bei wenigen Aufrufen fallen p95
            // und p99 auf denselben Fall, und zwei gleiche Zeilen sähen aus wie
            // ein Fehler.
            if ($match === null || in_array($match->event_id, $seen, true)) {
                continue;
            }

            $seen[] = $match->event_id;

            $samples[] = new TransactionSample(
                percentile: $percentile,
                eventId: $match->event_id,
                traceId: $match->trace_id,
                durationUs: $match->duration_us,
                spanCount: $match->span_count,
                release: $match->release,
                startedAt: $match->started_at,
                // Die Trace-Ansicht ist PF4. Solange es sie nicht gibt, steht
                // die Zeile ohne Link da — dieselbe Entscheidung wie in der
                // Navigation ({@see \App\Support\ShellData}): lieber kein Link
                // als ein toter.
                traceHref: $traceRoute ? route('traces.show', ['trace' => $match->trace_id]) : null,
            );
        }

        return $samples;
    }

    /**
     * Der Aufruf an einer Perzentil-Stelle der sortierten Stichprobe.
     *
     * Aufgerundet, damit das p100 die letzte Messung trifft und nicht die
     * vorletzte — dieselbe Rechnung wie in
     * {@see DurationHistogram::percentile()}, damit Kennzahl und Beispiel
     * denselben Begriff von „p95" benutzen.
     *
     * @param  list<Transaction>  $sorted  Aufsteigend nach Dauer
     */
    private static function atPercentile(array $sorted, float $percentile): ?Transaction
    {
        $total = count($sorted);

        if ($total === 0) {
            return null;
        }

        $rank = (int) max(1, ceil($percentile * $total));

        return $sorted[min($rank, $total) - 1];
    }

    /**
     * Die Aufschlüsselung nach Merkmalen.
     *
     * Version, Umgebung und Plattform, weil das die drei sind, die an der
     * Messung selbst stehen. Der Browser gehört fachlich dazu, steht aber nur an
     * den Fehlermeldungen und nicht an den Antwortzeiten — ihn hier zu zeigen
     * hieße, ihn zu erfinden.
     *
     * @param  Collection<int, Transaction>  $sample
     * @return list<TransactionFacet>
     */
    private function facets(Collection $sample): array
    {
        if ($sample->isEmpty()) {
            return [];
        }

        $keys = [
            'release' => fn (Transaction $transaction): ?string => $transaction->release,
            // Die Umgebung steht immer da — die Aufnahme setzt sie, weil sie im
            // eindeutigen Schlüssel der Vorberechnung liegt.
            'environment' => fn (Transaction $transaction): string => $transaction->environment,
            'platform' => fn (Transaction $transaction): ?string => $transaction->platform,
        ];

        $facets = [];

        foreach ($keys as $key => $read) {
            /** @var array<string, DurationHistogram> $histograms */
            $histograms = [];

            foreach ($sample as $transaction) {
                $value = $read($transaction);

                // Messungen ohne Angabe fallen weg statt unter „unbekannt" zu
                // laufen: eine Sammelzeile aus allem, was das SDK nicht
                // mitgeschickt hat, sähe aus wie ein Wert und wäre keiner.
                if ($value === null || $value === '') {
                    continue;
                }

                $histograms[$value] ??= DurationHistogram::empty();
                $histograms[$value]->add($transaction->duration_us);
            }

            // Ein Merkmal mit genau einem Wert sagt nichts — es ist der
            // Regelfall bei der Umgebung, wenn die Filterleiste bereits eine
            // gewählt hat.
            if (count($histograms) < 2) {
                continue;
            }

            $facets[] = TransactionFacet::build($key, $histograms);
        }

        return $facets;
    }

    /**
     * Abfrage 8: die Fehler, die unter diesem Transaktionsnamen gemeldet wurden.
     *
     * Der Weg führt über die Meldungen und nicht über die Einträge: der
     * Transaktionsname steht an der einzelnen Meldung (`events.transaction`),
     * ein Eintrag fasst Meldungen mehrerer Aufrufe zusammen und hat deshalb
     * keinen. Gezählt wird, wie oft ein Eintrag **in diesem Zeitraum unter
     * diesem Namen** auftrat — nicht seine Gesamtzahl, die etwas anderes ist.
     *
     * Zwischen Meldung und Eintrag steht die Gruppe: eine Meldung gehört zu
     * einem Fingerabdruck (`event_groups`), und erst der Fingerabdruck gehört
     * zu einem Eintrag. Der Umweg ist nicht zu sparen — ab S9 hängen mehrere
     * Gruppen an einem Eintrag, und eine Abkürzung an der Gruppe vorbei wäre
     * genau dann still falsch.
     *
     * @return list<array{id: int, title: string, culprit: string|null, count: int, href: string|null}>
     */
    private function issues(): array
    {
        $rows = $this->filter
            // Die Spalten ausdrücklich benannt: nach dem Verbund tragen beide
            // Tabellen ein `project_id`, und eine unbenannte Bedingung darauf
            // ist in beiden Datenbanken ein Fehler.
            ->apply(Event::query(), 'events.occurred_at', 'events.project_id', 'events.environment')
            ->where('events.transaction', $this->name)
            ->join('event_groups', 'event_groups.id', '=', 'events.event_group_id')
            ->whereNotNull('event_groups.issue_id')
            ->selectRaw('event_groups.issue_id as issue_id, count(*) as event_count')
            ->groupBy('event_groups.issue_id')
            ->orderByDesc('event_count')
            ->limit(self::ISSUE_LIMIT)
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $counts = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $counts[(int) $values['issue_id']] = (int) ($values['event_count'] ?? 0);
        }

        $issues = Issue::query()
            ->whereIn('id', array_keys($counts))
            ->get(['id', 'title', 'culprit']);

        $hasDetail = Route::has('issues.show');
        $linked = [];

        foreach ($issues as $issue) {
            $linked[] = [
                'id' => $issue->id,
                'title' => $issue->title ?? $issue->culprit ?? __('issues.list.untitled'),
                'culprit' => $issue->culprit,
                'count' => $counts[$issue->id] ?? 0,
                // Die Fehler-Detailseite ist S2. Bis es sie gibt, steht der
                // Eintrag ohne Link da.
                'href' => $hasDetail ? route('issues.show', $issue) : null,
            ];
        }

        // Nach Häufigkeit, weil die Reihenfolge der zweiten Abfrage die der
        // Kennungen ist und nicht die der Zählung.
        usort($linked, fn (array $a, array $b): int => [$b['count'], $a['title']] <=> [$a['count'], $b['title']]);

        return $linked;
    }

    /**
     * Die Verteilung als Balken: je Klasse ihre Grenzen und ihre Häufigkeit.
     *
     * Abgeschnitten wird an der ersten und letzten belegten Klasse. Alle
     * 31 Klassen zu zeigen hieße, eine Grafik zu zeichnen, die zu vier Fünfteln
     * aus Nullen besteht — von Mikrosekunden bis dreißig Stunden ist für eine
     * einzelne Seite immer zu viel.
     *
     * @return list<array{fromUs: int, toUs: int|null, count: int}>
     */
    private static function histogramBars(DurationHistogram $histogram): array
    {
        $buckets = $histogram->toArray();

        if ($buckets === []) {
            return [];
        }

        $first = (int) array_key_first($buckets);
        $last = (int) array_key_last($buckets);

        $bars = [];

        for ($bucket = $first; $bucket <= $last; $bucket++) {
            $bars[] = [
                'fromUs' => DurationHistogram::lowerBound($bucket),
                // Die letzte Klasse ist oben offen: dort steht alles, was länger
                // lief als ihre Untergrenze.
                'toUs' => $bucket >= DurationHistogram::MAX_BUCKET
                    ? null
                    : DurationHistogram::lowerBound($bucket + 1),
                'count' => $buckets[$bucket] ?? 0,
            ];
        }

        return $bars;
    }

    /**
     * Die Grundabfrage auf der Vorberechnung: dieser Name, diese Operation, im
     * gewählten Zeitraum.
     *
     * @return Builder<TransactionAggregate>
     */
    private function aggregates(): Builder
    {
        return $this->filter
            ->apply(TransactionAggregate::query(), 'window_start')
            ->where('name', $this->name)
            ->where('op', $this->op);
    }

    /**
     * Die Auflösung des Verlaufs.
     */
    private function period(): CountPeriod
    {
        return $this->filter->fromUtc()->diffInHours($this->filter->toUtc()) > self::HOURLY_LIMIT_HOURS
            ? CountPeriod::Day
            : CountPeriod::Hour;
    }

    /**
     * Die Länge des Zeitraums in Minuten — der Nenner des Durchsatzes.
     */
    private function minutes(): int
    {
        $seconds = $this->filter->toUtc()->getTimestamp() - $this->filter->fromUtc()->getTimestamp();

        return max(1, (int) ceil($seconds / 60));
    }
}
