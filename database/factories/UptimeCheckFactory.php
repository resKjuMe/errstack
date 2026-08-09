<?php

namespace Database\Factories;

use App\Enums\UptimeCheckOutcome;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UptimeCheck>
 */
class UptimeCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monitor = UptimeMonitor::factory();

        return [
            'uptime_monitor_id' => $monitor,
            // Dieselbe Fabrik wie oben würde ein zweites Projekt anlegen; das
            // Projekt der Prüfung ist zwingend das des Monitors.
            'project_id' => fn (array $attributes): int => UptimeMonitor::query()
                ->whereKey($attributes['uptime_monitor_id'])
                ->value('project_id'),
            'outcome' => UptimeCheckOutcome::Up,
            'http_status' => 200,
            'response_time_ms' => fake()->numberBetween(20, 400),
            'error' => null,
            'attempts' => 1,
            'checked_at' => now(),
        ];
    }

    /**
     * Eine gescheiterte Messung: ohne Antwort, ohne Zeit.
     */
    public function failed(UptimeCheckOutcome $outcome = UptimeCheckOutcome::ConnectionFailed): self
    {
        return $this->state(fn (): array => [
            'outcome' => $outcome,
            'http_status' => null,
            'response_time_ms' => null,
            'error' => 'Verbindung fehlgeschlagen',
        ]);
    }
}
