<?php

namespace Database\Factories;

use App\Enums\InboundFilterKind;
use App\Models\InboundFilterRule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundFilterRule>
 */
class InboundFilterRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'kind' => InboundFilterKind::MessagePattern,
            'expression' => '*ResizeObserver loop*',
            'is_active' => true,
        ];
    }

    public function of(InboundFilterKind $kind, string $expression): self
    {
        return $this->state(['kind' => $kind, 'expression' => $expression]);
    }

    public function forProject(Project $project): self
    {
        return $this->state(['project_id' => $project->id]);
    }
}
