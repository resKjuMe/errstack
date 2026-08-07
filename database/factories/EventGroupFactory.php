<?php

namespace Database\Factories;

use App\Enums\GroupingSource;
use App\Models\EventGroup;
use App\Models\Project;
use App\Support\Ingest\Grouping\Fingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventGroup>
 */
class EventGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['RuntimeException', 'TypeError', 'LogicException']);
        $frame = 'handle in app/Http/Controllers/ReportController.php';

        $values = ['error.type='.$type, 'stack.frame='.$frame];

        return [
            'project_id' => Project::factory(),
            'fingerprint' => Fingerprint::hash($values),
            'source' => GroupingSource::Stacktrace,
            'components' => [
                ['name' => 'error.type', 'value' => $type],
                ['name' => 'stack.frame', 'value' => $frame],
            ],
        ];
    }

    /**
     * Eine Gruppe, die aus einer eigenen Angabe des SDK entstand.
     */
    public function custom(string ...$values): static
    {
        $values = $values === [] ? ['abrechnung'] : array_values($values);

        return $this->state(fn (): array => [
            'fingerprint' => Fingerprint::hash($values),
            'source' => GroupingSource::Custom,
            'components' => [],
        ]);
    }
}
