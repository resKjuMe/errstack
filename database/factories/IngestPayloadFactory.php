<?php

namespace Database\Factories;

use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestPayload>
 */
class IngestPayloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = IngestPayload::freshEventId();

        $payload = (string) json_encode([
            'event_id' => $eventId,
            'timestamp' => now()->toIso8601String(),
            'platform' => 'php',
            'message' => fake()->sentence(),
        ]);

        return [
            'project_id' => Project::factory(),
            'project_key_id' => null,
            'event_id' => $eventId,
            'type' => IngestType::Event,
            'sdk' => 'sentry.php/4.0.0',
            'payload' => $payload,
            'size_bytes' => strlen($payload),

            // Eine erzeugte Meldung ist eine angenommene: sie wartet auf ihre
            // Auswertung, wie jede über den Endpunkt.
            'processing_state' => ProcessingState::Pending,
            'attempts' => 0,
        ];
    }

    /**
     * Meldung, die über einen bestimmten Schlüssel hereinkam — wie im Betrieb,
     * wo jede Meldung ihre DSN mitbringt.
     */
    public function viaKey(ProjectKey $key): static
    {
        return $this->state(fn (): array => [
            'project_id' => $key->project_id,
            'project_key_id' => $key->id,
        ]);
    }
}
