<?php

namespace App\Support\Performance;

/**
 * Das Ergebnis der Detailanalyse — alles, was die Seite zeigt, in einer Form,
 * die der Controller nur noch weiterreicht.
 */
final class TransactionDetailResult
{
    /**
     * @param  TransactionOverviewRow  $summary  Dieselben Kennzahlen wie in der
     *                                           Übersicht, damit die Detailseite
     *                                           dieselben Zahlen nennt wie die
     *                                           Zeile, aus der man kam
     * @param  list<array{fromUs: int, toUs: int|null, count: int}>  $histogram
     * @param  list<array{window: string, count: int, p95Us: int|null, failureRate: float|null}>  $series
     * @param  list<SpanBreakdownRow>  $spans
     * @param  list<TransactionSample>  $samples
     * @param  list<TransactionFacet>  $facets
     * @param  list<array{id: int, title: string, culprit: string|null, count: int, href: string|null}>  $issues
     * @param  int  $sampledTransactions  Auf wie vielen Einzelmessungen die
     *                                    Aufschlüsselungen beruhen
     */
    public function __construct(
        public readonly string $name,
        public readonly string $op,
        public readonly TransactionOverviewRow $summary,
        public readonly array $histogram,
        public readonly string $seriesPeriod,
        public readonly array $series,
        public readonly array $spans,
        public readonly array $samples,
        public readonly array $facets,
        public readonly array $issues,
        public readonly int $sampledTransactions,
        public readonly int $sampleLimit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'op' => $this->op,
            'summary' => $this->summary->toArray(),
            'histogram' => $this->histogram,
            'series' => [
                'period' => $this->seriesPeriod,
                'points' => $this->series,
            ],
            'spans' => array_map(
                fn (SpanBreakdownRow $row): array => $row->toArray(),
                $this->spans,
            ),
            'samples' => array_map(
                fn (TransactionSample $sample): array => $sample->toArray(),
                $this->samples,
            ),
            'facets' => array_map(
                fn (TransactionFacet $facet): array => $facet->toArray(),
                $this->facets,
            ),
            'issues' => $this->issues,
            // Die Stichprobe steht in der Nutzlast, weil die Seite sie nennen
            // muss: „aus 500 von 12.000 Messungen" ist eine andere Aussage als
            // „aus allen", und der Unterschied gehört nicht in eine Fußnote,
            // die niemand schreibt.
            'sample' => [
                'transactions' => $this->sampledTransactions,
                'limit' => $this->sampleLimit,
            ],
        ];
    }
}
