<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'organization_id' => Organization::factory(),
            // Je Organisation ist der Name eindeutig; Tests mit mehreren
            // Repositories an derselben geben ihn deshalb selbst an.
            'name' => 'acme/'.$name,
            'provider' => Repository::PROVIDER_MANUAL,
            'url' => 'https://github.com/acme/'.$name,
            'external_id' => null,
        ];
    }

    /**
     * Ein Repository ohne Adresse — wie es entsteht, wenn eine Bauumgebung nur
     * seinen Namen mitschickt.
     */
    public function withoutUrl(): static
    {
        return $this->state(['url' => null]);
    }
}
