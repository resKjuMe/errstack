<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seenAt = Carbon::now()->subDays(fake()->numberBetween(1, 30));

        return [
            'project_id' => Project::factory(),
            // Je Projekt ist der Name eindeutig; Tests mit mehreren Umgebungen
            // am selben Projekt geben ihn deshalb selbst an.
            'name' => fake()->randomElement(['production', 'staging', 'preview', 'development']),
            'is_hidden' => false,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt->copy()->addHours(fake()->numberBetween(1, 24)),
        ];
    }

    public function hidden(): static
    {
        return $this->state(['is_hidden' => true]);
    }
}
