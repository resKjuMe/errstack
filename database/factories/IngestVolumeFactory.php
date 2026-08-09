<?php

namespace Database\Factories;

use App\Models\IngestVolume;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestVolume>
 */
class IngestVolumeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'bucket' => IngestVolume::bucket(),
            'quantity' => fake()->numberBetween(0, 200),
            'throttled' => false,
        ];
    }

    /**
     * Eine gedrosselte Minute — sie zählt beim Vergleichswert nicht mit.
     */
    public function throttled(): static
    {
        return $this->state(fn (): array => ['throttled' => true]);
    }
}
