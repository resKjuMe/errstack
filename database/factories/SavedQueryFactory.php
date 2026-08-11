<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SavedQuery;
use App\Models\User;
use App\Support\Discover\Dataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedQuery>
 */
class SavedQueryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'name' => 'Auswertung '.fake()->unique()->numberBetween(1, 100000),
            'description' => '',
            // Die Anzahl je Quelle — die eine Frage, die jede Quelle beantworten
            // kann, und damit die einzige, die als Voreinstellung nirgends
            // scheitert.
            'query' => [
                'dataset' => Dataset::Errors->value,
                'fields' => [],
                'metrics' => ['count()'],
                'q' => '',
                'sort' => '',
                'limit' => 50,
                'interval' => null,
            ],
            'filters' => null,
            'shared' => false,
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
