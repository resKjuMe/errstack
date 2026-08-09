<?php

namespace Database\Factories;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Models\Issue;
use App\Models\IssueLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueLink>
 */
class IssueLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 9999);

        return [
            'issue_id' => Issue::factory(),
            'provider' => IntegrationProvider::GitHub,
            'repository' => 'acme/webshop',
            'number' => $number,
            'title' => fake()->sentence(4),
            'url' => 'https://github.com/acme/webshop/issues/'.$number,
            'state' => ExternalIssueState::Open,
            'created_remotely' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(['state' => ExternalIssueState::Closed]);
    }
}
