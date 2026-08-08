<?php

namespace Database\Factories;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueComment>
 */
class IssueCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'author_name' => fake()->name(),
            'body' => fake()->sentence(),
            'edited_at' => null,
        ];
    }
}
