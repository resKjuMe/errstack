<?php

namespace Database\Factories;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Quota;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quota>
 */
class QuotaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => QuotaScope::Project,
            'scope_id' => Project::factory(),
            'category' => QuotaCategory::Errors,
            'per_month' => fake()->numberBetween(1_000, 1_000_000),
            'per_minute' => null,
            'warned_period' => null,
            'warned_percent' => null,
        ];
    }

    public function forProject(Project $project, QuotaCategory $category = QuotaCategory::Errors): static
    {
        return $this->state(fn (): array => [
            'scope' => QuotaScope::Project,
            'scope_id' => $project->id,
            'category' => $category,
        ]);
    }

    public function forOrganization(Organization $organization, QuotaCategory $category = QuotaCategory::Errors): static
    {
        return $this->state(fn (): array => [
            'scope' => QuotaScope::Organization,
            'scope_id' => $organization->id,
            'category' => $category,
        ]);
    }
}
