<?php

namespace Database\Factories;

use App\Enums\TrendDirection;
use App\Models\Project;
use App\Models\TransactionTrendDetection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Festgestellte Trendbrüche für die Tests der Liste.
 *
 * Der Umweg über den Durchlauf ({@see App\Support\Performance\Trends\TrendScan})
 * wäre für einen Test der Ansicht der falsche: er müsste je Zeile ein paar
 * hundert Fenster anlegen, damit am Ende eine Zeile entsteht — geprüft wäre
 * dann die Erkennung und nicht die Darstellung. Die Erkennung selbst hat ihren
 * eigenen Test, und der geht ohne Datenbank aus.
 *
 * @extends Factory<TransactionTrendDetection>
 */
class TransactionTrendDetectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $beforeUs = 200_000;
        $afterUs = 800_000;

        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'name' => 'GET /'.fake()->slug(2),
            'op' => 'http.server',
            'direction' => TrendDirection::Worse,
            'breakpoint_at' => CarbonImmutable::now()->subDay()->startOfHour(),
            'before_p95_us' => $beforeUs,
            'after_p95_us' => $afterUs,
            'before_count' => 1_200,
            'after_count' => 1_100,
            'change_ratio' => $afterUs / $beforeUs - 1.0,
            'z_score' => 6.4,
            'deploy_id' => null,
            'detected_at' => CarbonImmutable::now(),
            'notified_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Eine Verbesserung: dieselben Zahlen mit vertauschten Seiten.
     */
    public function improvement(): static
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => TrendDirection::Better,
            'before_p95_us' => $attributes['after_p95_us'],
            'after_p95_us' => $attributes['before_p95_us'],
            'change_ratio' => $attributes['before_p95_us'] / $attributes['after_p95_us'] - 1.0,
        ]);
    }

    public function forTransaction(string $name, string $op = 'http.server'): static
    {
        return $this->state(fn (): array => ['name' => $name, 'op' => $op]);
    }

    public function seen(): static
    {
        return $this->state(fn (): array => ['seen_at' => CarbonImmutable::now()]);
    }
}
