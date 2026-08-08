<?php

namespace Database\Factories;

use App\Enums\PerformanceProblem;
use App\Models\IngestPayload;
use App\Models\PerformanceDetection;
use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceDetection>
 */
class PerformanceDetectionFactory extends Factory
{
    protected $model = PerformanceDetection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'issue_id' => null,
            'transaction_id' => Transaction::factory(),
            'trace_id' => IngestPayload::freshEventId(),
            'problem' => PerformanceProblem::NPlusOneQueries->value,
            // Kein fester Wert: zwei Funde derselben Fabrik dürfen sich nicht
            // am eindeutigen Index (Ablauf, Fingerabdruck) gegenseitig
            // ausschließen.
            'fingerprint' => substr(md5(fake()->unique()->uuid()), 0, 32),
            'span_ids' => ['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb'],
            'description' => 'select * from projects where id = ?',
            'span_count' => 2,
            'time_lost_us' => fake()->numberBetween(1_000, 500_000),
            'evidence' => ['repeats' => 2],
            'occurred_at' => now(),
        ];
    }
}
