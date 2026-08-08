<?php

namespace Database\Factories;

use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionSpan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TransactionSpan>
 */
class TransactionSpanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now();

        // Von einer halben Millisekunde bis zu einer halben Sekunde: der
        // Bereich, in dem sich Abfragen und Aufrufe fremder Dienste bewegen.
        $durationUs = fake()->numberBetween(500, 500_000);

        return [
            'transaction_id' => Transaction::factory(),
            'project_id' => Project::factory(),
            'trace_id' => IngestPayload::freshEventId(),
            'span_id' => substr(IngestPayload::freshEventId(), 0, 16),
            'parent_span_id' => null,
            'op' => 'db.sql.query',
            'description' => 'select * from "users" where "id" = ?',
            'status' => 'ok',
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMicroseconds($durationUs),
            'duration_us' => $durationUs,
            'data' => null,
            'position' => 0,
        ];
    }

    /**
     * Ein Schritt innerhalb einer bestimmten Transaktion.
     *
     * Nimmt Projekt und Spur von ihr, weil beides an der Zeile doppelt geführt
     * wird ({@see TransactionSpan}) — und ein Schritt, dessen Spur eine andere
     * ist als die seiner Transaktion, wäre ein Datenbestand, den es nicht gibt.
     */
    public function of(Transaction $transaction, ?string $parentSpanId = null): static
    {
        return $this->state(fn (): array => [
            'transaction_id' => $transaction->id,
            'project_id' => $transaction->project_id,
            'trace_id' => $transaction->trace_id,
            'parent_span_id' => $parentSpanId ?? $transaction->span_id,
        ]);
    }

    /**
     * Ein Schritt mit festgelegtem Anfang und fester Dauer — für Tests, die über
     * die Zeitachse rechnen.
     */
    public function between(string $startedAt, int $durationUs): static
    {
        return $this->state(fn (): array => [
            'started_at' => $startedAt,
            'finished_at' => Carbon::parse($startedAt)->addMicroseconds($durationUs),
            'duration_us' => $durationUs,
        ]);
    }
}
