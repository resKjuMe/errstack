<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Support\Performance\DurationHistogram;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Vorberechnete Zeitfenster für die Auswertungen.
 *
 * Der Umweg über die Aufnahme wäre für einen Test der Übersicht der falsche:
 * eine Messung durch die ganze Verarbeitungskette zu schicken, nur damit am Ende
 * eine Zahl in einem Fenster steht, prüft die Kette und nicht die Auswertung —
 * und für hundert Fenster wären es hundert Durchläufe.
 *
 * @extends Factory<TransactionAggregate>
 */
class TransactionAggregateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Derselbe Bereich wie in {@see TransactionFactory}: zwischen 5 ms und
        // 2 s bewegen sich echte Antwortzeiten. Ein Fenster mit unplausiblen
        // Zahlen prüft eine Anzeige gegen Werte, die es nie gibt.
        $durationUs = fake()->numberBetween(5_000, 2_000_000);
        $count = fake()->numberBetween(1, 50);

        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'name' => 'GET /'.fake()->slug(2),
            'op' => 'http.server',
            'window_start' => Transaction::windowFor(CarbonImmutable::now()),
            'transaction_count' => $count,
            // Ohne Stichprobe laufen gemessene und hochgerechnete Anzahl gleich
            // — das ist der Regelfall, den ein Test nicht eigens setzen soll.
            'extrapolated_count' => $count,
            'failure_count' => 0,
            'duration_sum_us' => $durationUs * $count,
            'duration_min_us' => $durationUs,
            'duration_max_us' => $durationUs,
            'duration_histogram' => [DurationHistogram::bucketFor($durationUs) => $count],
        ];
    }

    /**
     * Ein Fenster mit lauter gleich langen Messungen.
     *
     * Der Regelfall für Tests über Perzentile: liegen alle Messungen in
     * derselben Klasse, ist jedes Perzentil deren Obergrenze — und damit
     * vorhersagbar, ohne die Rechnung der Verteilung nachzubauen.
     */
    public function measuring(int $durationUs, int $count = 1, int $failures = 0): static
    {
        return $this->state(fn (): array => [
            'transaction_count' => $count,
            'extrapolated_count' => $count,
            'failure_count' => $failures,
            'duration_sum_us' => $durationUs * $count,
            'duration_min_us' => $durationUs,
            'duration_max_us' => $durationUs,
            'duration_histogram' => [DurationHistogram::bucketFor($durationUs) => $count],
        ]);
    }

    public function named(string $name, string $op = 'http.server'): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'op' => $op,
        ]);
    }

    /**
     * Verschiebt das Fenster. Der Zeitpunkt wird auf das Minutenraster
     * abgeschnitten, wie es die Aufnahme täte — ein Fenster mit Sekunden im
     * Anfang gäbe es im Betrieb nicht.
     */
    public function at(CarbonImmutable|string $when): static
    {
        return $this->state(fn (): array => [
            'window_start' => Transaction::windowFor(
                $when instanceof CarbonImmutable ? $when : CarbonImmutable::parse($when),
            ),
        ]);
    }

    public function inEnvironment(string $environment): static
    {
        return $this->state(fn (): array => ['environment' => $environment]);
    }

    /**
     * Ein Fenster, das aus einer Stichprobe stammt: dieselben Messungen, aber
     * hochgerechnet auf das :factor-Fache an tatsächlichen Aufrufen.
     */
    public function extrapolating(float $factor): static
    {
        return $this->state(fn (array $attributes): array => [
            'extrapolated_count' => ((float) $attributes['transaction_count']) * $factor,
        ]);
    }
}
