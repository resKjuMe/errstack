<?php

namespace Database\Factories;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Models\IngestDiscard;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestDiscard>
 */
class IngestDiscardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'project_key_id' => null,
            'origin' => DiscardOrigin::Server,
            'reason' => DiscardReason::UnknownType->value,
            'category' => 'span',
            'bucket' => IngestDiscard::bucket(),
            'quantity' => fake()->numberBetween(1, 50),
        ];
    }

    /**
     * Was ein SDK selbst verworfen hat — mit dessen eigener Begründung.
     */
    public function fromClient(string $reason = 'queue_overflow', string $category = 'error'): static
    {
        return $this->state(fn (): array => [
            'origin' => DiscardOrigin::Client,
            'reason' => $reason,
            'category' => $category,
        ]);
    }

    public function viaKey(ProjectKey $key): static
    {
        return $this->state(fn (): array => [
            'project_id' => $key->project_id,
            'project_key_id' => $key->id,
        ]);
    }
}
