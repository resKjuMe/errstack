<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SamplingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SamplingRule>
 */
class SamplingRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Gesundheitsprüfung ausdünnen',
            'transaction_name' => 'GET /health',
            'environment' => null,
            'release' => null,
            'op' => null,
            'sample_rate' => 0.01,
            'minimum_per_window' => 1,
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Eine Regel mit eigener Quote.
     */
    public function keeping(float $rate): static
    {
        return $this->state(fn (): array => ['sample_rate' => $rate]);
    }

    /**
     * Eine Regel ohne Bedingung: sie trifft auf jeden Aufruf zu und ist damit
     * die Vorgabe des Projekts.
     */
    public function catchAll(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Vorgabe des Projekts',
            'transaction_name' => null,
            'environment' => null,
            'release' => null,
            'op' => null,
        ]);
    }

    /**
     * Ohne Mindestquote — für Prüfungen, in denen ausschließlich der Wurf
     * entscheiden soll.
     */
    public function withoutMinimum(): static
    {
        return $this->state(fn (): array => ['minimum_per_window' => 0]);
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
