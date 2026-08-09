<?php

namespace Database\Factories;

use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\Replay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Replay>
 */
class ReplayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(1, 120));
        $durationMs = fake()->numberBetween(5_000, 600_000);

        return [
            'project_id' => Project::factory(),
            'replay_id' => IngestPayload::freshEventId(),
            'environment' => 'production',
            'release' => null,
            'dist' => null,
            'platform' => 'javascript',
            'sdk' => 'sentry.javascript.browser 8.42.0',
            'url' => 'https://example.com/kasse',
            'urls' => ['https://example.com/kasse'],
            'user' => ['id' => (string) fake()->numberBetween(1, 9999)],
            'browser' => 'Chrome 120',
            'os' => 'Windows 11',
            'device' => null,
            'masked' => true,
            'started_at' => $startedAt,
            'last_segment_at' => $startedAt->copy()->addMilliseconds($durationMs),
            'finished_at' => null,
            'duration_ms' => $durationMs,
            // Die Vorgabe ist eine **abspielbare** Aufzeichnung: eine ohne
            // Abschnitte taucht in keiner Liste auf ({@see Replay::scopePlayable()}),
            // und ein Test, der sie anlegt und sich über die leere Seite wundert,
            // ist die Art von Sackgasse, die eine Factory verhindern soll.
            'segment_count' => 1,
            'event_count' => 12,
            'error_count' => 0,
            'size_bytes' => 4096,
        ];
    }

    /**
     * Eine Zeile ohne Bilddaten — der Anker, den eine Fehlermeldung anlegt,
     * bevor die Aufnahme eintrifft.
     */
    public function empty(): static
    {
        return $this->state(fn (): array => [
            'segment_count' => 0,
            'event_count' => 0,
            'size_bytes' => 0,
        ]);
    }
}
