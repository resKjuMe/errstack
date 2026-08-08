<?php

namespace Database\Factories;

use App\Enums\IssueSort;
use App\Models\Organization;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            // Der Name muss je Organisation und Konto eindeutig sein; zwei
            // Suchen aus der Fabrik dürfen sich daran nicht stoßen.
            'name' => 'Ansicht '.fake()->unique()->numberBetween(1, 100000),
            'query' => 'is:unresolved',
            'sort' => IssueSort::LastSeen,
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
