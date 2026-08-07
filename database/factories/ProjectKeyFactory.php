<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectKey>
 */
class ProjectKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(2, true),
            'public_key' => ProjectKey::freshPublicKey(),
            'active' => true,
            'rate_limit_per_minute' => null,
        ];
    }
}
