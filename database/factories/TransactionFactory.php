<?php

namespace Database\Factories;

use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subSeconds(fake()->numberBetween(1, 600));

        // Zwischen 5 ms und 2 s — der Bereich, in dem sich echte Antwortzeiten
        // bewegen. Erzeugte Messungen sollen in einer Verteilung plausibel
        // aussehen, sonst prüft ein Test die Anzeige gegen Zahlen, die es nie
        // gibt.
        $durationUs = fake()->numberBetween(5_000, 2_000_000);

        return [
            'project_id' => Project::factory(),
            'ingest_payload_id' => null,
            'event_id' => IngestPayload::freshEventId(),
            'trace_id' => IngestPayload::freshEventId(),
            'span_id' => substr(IngestPayload::freshEventId(), 0, 16),
            'parent_span_id' => null,
            'name' => 'GET /'.fake()->slug(2),
            'op' => 'http.server',
            'source' => 'route',
            'status' => 'ok',
            'platform' => 'php',
            'environment' => 'production',
            'release' => null,
            'user_identifier' => null,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMicroseconds($durationUs),
            'duration_us' => $durationUs,
            'span_count' => 0,
            'measurements' => null,
        ];
    }

    /**
     * Eine Messung mit festgelegter Dauer — für Tests, die über Mittelwerte,
     * Grenzwerte oder Perzentile rechnen.
     */
    public function lasting(int $durationUs): static
    {
        return $this->state(fn (array $attributes): array => [
            'duration_us' => $durationUs,
            // `Carbon::parse`, weil der Anfang auch als Text übergeben werden
            // kann — eine Zustandsmethode darf nicht davon abhängen, in welcher
            // Form der Aufrufer ihn gesetzt hat.
            'finished_at' => Carbon::parse($attributes['started_at'])->addMicroseconds($durationUs),
        ]);
    }

    /**
     * Ein Aufruf, der nicht erfolgreich war.
     */
    public function failed(string $status = 'internal_error'): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }
}
