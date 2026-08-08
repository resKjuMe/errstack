<?php

namespace Database\Factories;

use App\Models\IssueDiscard;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueDiscard>
 */
class IssueDiscardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'title' => 'RuntimeException: '.fake()->sentence(3),
            'user_id' => null,
        ];
    }
}
