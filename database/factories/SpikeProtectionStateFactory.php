<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SpikeProtectionState;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SpikeProtectionState>
 */
class SpikeProtectionStateFactory extends Factory
{
    /**
     * Standardmäßig eine **laufende** Drosselung: das ist der Zustand, um den
     * es in den meisten Prüfungen geht.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'started_at' => Carbon::now()->subMinutes(3),
            'ended_at' => null,
            'baseline' => 20.0,
            'threshold' => 100,
            'peak' => 250,
            'discarded' => 0,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (): array => ['ended_at' => Carbon::now()->subMinute()]);
    }
}
