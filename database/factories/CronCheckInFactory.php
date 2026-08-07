<?php

namespace Database\Factories;

use App\Enums\CronCheckInStatus;
use App\Models\CronCheckIn;
use App\Models\CronMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CronCheckIn>
 */
class CronCheckInFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monitor = CronMonitor::factory();

        return [
            'cron_monitor_id' => $monitor,
            // Ohne eigene Angabe erbt der Eintrag das Projekt seines Monitors —
            // sonst zeigten die beiden Spalten auf verschiedene Projekte.
            'project_id' => fn (array $attributes): int => CronMonitor::query()
                ->whereKey($attributes['cron_monitor_id'])
                ->value('project_id'),
            'check_in_id' => null,
            'status' => CronCheckInStatus::Ok,
            'environment' => null,
            'expected_at' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 1500,
        ];
    }

    /**
     * Eine begonnene, noch nicht abgeschlossene Ausführung.
     */
    public function running(): self
    {
        return $this->state([
            'status' => CronCheckInStatus::InProgress,
            'finished_at' => null,
            'duration_ms' => null,
        ]);
    }
}
