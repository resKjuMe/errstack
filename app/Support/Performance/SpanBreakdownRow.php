<?php

namespace App\Support\Performance;

/**
 * Ein Zeitanteil der Aufschlüsselung: alles, was in den betrachteten Aufrufen
 * unter derselben Vorgangsart lief.
 *
 * Die Zahlen sind **Summen über die Stichprobe** und keine Hochrechnung. Sie
 * beantworten „wo geht die Zeit hin", nicht „wie viel Zeit war es insgesamt" —
 * und für die erste Frage ist der Anteil die Antwort, nicht der Absolutwert.
 */
final class SpanBreakdownRow
{
    /**
     * Der Anteil an der Gesamtzeit aller aufgeschlüsselten Schritte, zwischen
     * 0 und 1.
     */
    public readonly float $share;

    /**
     * Die mittlere Dauer eines Schritts dieser Art.
     */
    public readonly float $averageUs;

    /**
     * @param  string  $op  Die Vorgangsart (`db.sql.query`, `http.client`, …)
     * @param  int  $count  Wie viele Schritte dieser Art vorkamen
     * @param  int  $totalUs  Ihre summierte Dauer
     * @param  int  $transactions  In wie vielen der betrachteten Aufrufe sie vorkam
     * @param  string|null  $example  Eine Beschreibung als Beleg (das SQL, die Adresse)
     */
    public function __construct(
        public readonly string $op,
        public readonly int $count,
        public readonly int $totalUs,
        public readonly int $transactions,
        public readonly ?string $example,
        int $breakdownTotalUs,
    ) {
        $this->share = $breakdownTotalUs === 0 ? 0.0 : $totalUs / $breakdownTotalUs;
        $this->averageUs = $count === 0 ? 0.0 : $totalUs / $count;
    }

    /**
     * @return array{op: string, count: int, totalUs: int, transactions: int, example: string|null, share: float, averageUs: int}
     */
    public function toArray(): array
    {
        return [
            'op' => $this->op,
            'count' => $this->count,
            'totalUs' => $this->totalUs,
            'transactions' => $this->transactions,
            'example' => $this->example,
            'share' => round($this->share, 4),
            'averageUs' => (int) round($this->averageUs),
        ];
    }
}
