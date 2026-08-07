<?php

namespace Database\Factories;

use App\Models\FingerprintRule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FingerprintRule>
 */
class FingerprintRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Zeitüberschreitungen zusammenfassen',
            'matchers' => [
                ['attribute' => 'error.type', 'pattern' => '*TimeoutException'],
            ],
            'fingerprint' => ['zeitueberschreitung'],
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Eine Regel mit eigenen Bedingungen und eigenem Fingerabdruck.
     *
     * @param  list<array{attribute: string, pattern: string, negated?: bool}>  $matchers
     * @param  list<string>  $fingerprint
     */
    public function rule(array $matchers, array $fingerprint): static
    {
        return $this->state(fn (): array => [
            'matchers' => $matchers,
            'fingerprint' => $fingerprint,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function at(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }
}
