<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'name' => 'Dashboard '.fake()->unique()->numberBetween(1, 100000),
            'description' => '',
            'shared' => false,
            'template' => null,
        ];
    }

    /**
     * Für die ganze Organisation sichtbar.
     */
    public function shared(): static
    {
        return $this->state(fn (): array => ['shared' => true]);
    }
}
