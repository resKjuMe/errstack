<?php

namespace Database\Factories;

use App\Enums\IssueActivityType;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueActivity>
 */
class IssueActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'project_id' => Project::factory(),
            'user_id' => null,
            'actor_name' => fake()->name(),
            'type' => IssueActivityType::Resolved,
            'data' => null,
        ];
    }
}
