<?php

namespace Database\Factories;

use App\Enums\ScrubRuleType;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ScrubRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrubRule>
 */
class ScrubRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            // Ohne Projekt: die organisationsweite Regel ist der Vorgabefall,
            // weil die Fabrik dann kein Projekt anlegen muss, das zur
            // Organisation passt.
            'project_id' => null,
            'type' => ScrubRuleType::Field,
            'expression' => 'kundennummer',
            'path' => null,
            'is_active' => true,
        ];
    }

    /**
     * Eine Regel, die nur für dieses Projekt gilt — samt der zugehörigen
     * Organisation, damit beide Angaben zueinander passen.
     */
    public function forProject(Project $project): self
    {
        return $this->state([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]);
    }

    public function pattern(string $expression): self
    {
        return $this->state([
            'type' => ScrubRuleType::Pattern,
            'expression' => $expression,
        ]);
    }
}
