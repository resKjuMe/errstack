<?php

namespace Database\Factories;

use App\Enums\EventLevel;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['RuntimeException', 'TypeError', 'LogicException']);
        $value = fake()->sentence();

        return [
            'project_id' => Project::factory(),
            'ingest_payload_id' => IngestPayload::factory(),
            'event_id' => IngestPayload::freshEventId(),
            'level' => EventLevel::Error,
            'platform' => 'php',
            'title' => $type.': '.$value,
            'culprit' => 'handle (app/Http/Controllers/ReportController.php)',
            'transaction' => null,
            'environment' => 'production',
            'release' => null,
            'occurred_at' => now(),
            'received_at' => now(),
            'exceptions' => [[
                'type' => $type,
                'value' => $value,
                'frames' => [[
                    'filename' => 'app/Http/Controllers/ReportController.php',
                    'function' => 'handle',
                    'lineno' => fake()->numberBetween(10, 400),
                    'in_app' => true,
                ]],
            ]],
        ];
    }

    /**
     * Eine Meldung ohne Ausnahme — nur ein Text, wie ihn `captureMessage()`
     * schickt. Der zweite Regelfall neben dem Absturz und derjenige, an dem
     * sich zeigt, ob eine Auswertung auch ohne Stacktrace auskommt.
     */
    public function message(?string $text = null): static
    {
        $text ??= fake()->sentence();

        return $this->state(fn (): array => [
            'level' => EventLevel::Info,
            'title' => $text,
            'culprit' => null,
            'exceptions' => null,
            'message' => ['formatted' => $text],
        ]);
    }

    /**
     * Eine Meldung, die zu einer bestimmten Ablage gehört. Ohne das erzeugt die
     * Fabrik eine eigene — und die Meldung hinge an anderen Rohdaten als der
     * Test annimmt.
     */
    public function forPayload(IngestPayload $payload): static
    {
        return $this->state(fn (): array => [
            'project_id' => $payload->project_id,
            'ingest_payload_id' => $payload->id,
            'event_id' => $payload->event_id,
        ]);
    }
}
