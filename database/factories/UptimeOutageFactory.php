<?php

namespace Database\Factories;

use App\Enums\UptimeCheckOutcome;
use App\Models\UptimeMonitor;
use App\Models\UptimeOutage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<UptimeOutage>
 */
class UptimeOutageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uptime_monitor_id' => UptimeMonitor::factory(),
            'project_id' => fn (array $attributes): int => UptimeMonitor::query()
                ->whereKey($attributes['uptime_monitor_id'])
                ->value('project_id'),
            'issue_id' => null,
            'outcome' => UptimeCheckOutcome::ConnectionFailed,
            'http_status' => null,
            'error' => 'Verbindung fehlgeschlagen',
            'started_at' => now()->subMinutes(10),
            // Vorgabe ist der **laufende** Vorfall: er ist der Zustand, um den
            // es bei einer Überwachung geht, und der abgeschlossene ist mit
            // `resolved()` einen Aufruf entfernt.
            'ended_at' => null,
            'duration_seconds' => null,
            'failed_checks' => 1,
        ];
    }

    /**
     * Ein abgeschlossener Ausfall.
     */
    public function resolved(int $durationSeconds = 600): self
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => Carbon::parse($attributes['started_at'])->addSeconds($durationSeconds),
            'duration_seconds' => $durationSeconds,
        ]);
    }
}
