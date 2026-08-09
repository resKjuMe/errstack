<?php

namespace Database\Factories;

use App\Models\Replay;
use App\Models\ReplaySegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplaySegment>
 */
class ReplaySegmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(5);

        return [
            // Zuerst die Sitzung, dann das Projekt aus ihr: einen Abschnitt in
            // einem anderen Projekt als seine Aufzeichnung gibt es im Feld
            // nicht, und ein Test dagegen prüft nichts.
            'replay_id' => Replay::factory(),
            'project_id' => static fn (array $attributes): int => (int) Replay::query()
                ->whereKey($attributes['replay_id'])
                ->value('project_id'),
            'ingest_payload_id' => null,
            'segment_id' => 0,
            'path' => 'replays/test/segment.json.gz',
            'size_bytes' => 2048,
            'event_count' => 6,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds(5),
        ];
    }
}
