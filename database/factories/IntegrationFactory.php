<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider' => IntegrationProvider::GitHub,
            'account' => 'acme',
            'external_id' => (string) fake()->unique()->numberBetween(1000, 99999),
            // Das Token steht nicht in `fillable` (siehe Integration) — die
            // Factory setzt es deshalb wie das Modell selbst, über die
            // Attribut-Liste.
            'credentials' => ['token' => 'gho_'.fake()->regexify('[A-Za-z0-9]{32}')],
            'status' => IntegrationStatus::Connected,
        ];
    }

    /**
     * Eine Anbindung, deren Zugang abgelehnt wurde — der Fall, für den es die
     * Anzeige „Verbindung verloren" gibt.
     */
    public function disconnected(): static
    {
        return $this->state([
            'status' => IntegrationStatus::Disconnected,
            'last_error' => 'Bad credentials',
            'last_error_at' => now(),
        ]);
    }
}
