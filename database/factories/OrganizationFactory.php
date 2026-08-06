<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'slug' => fn (array $attributes): string => Organization::uniqueSlug((string) $attributes['name']),
        ];
    }

    /**
     * Organisation mit einem bestimmten Mitglied — der Regelfall in Tests, weil
     * ohne Mitgliedschaft niemand an die Organisation herankommt.
     */
    public function withMember(User $user, OrganizationRole $role = OrganizationRole::Owner): static
    {
        return $this->afterCreating(
            fn (Organization $organization) => $organization->setRole($user, $role),
        );
    }
}
