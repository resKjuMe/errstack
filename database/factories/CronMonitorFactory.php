<?php

namespace Database\Factories;

use App\Enums\CronIntervalUnit;
use App\Enums\CronMonitorStatus;
use App\Enums\CronScheduleType;
use App\Models\CronMonitor;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CronMonitor>
 */
class CronMonitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'project_id' => Project::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'schedule_type' => CronScheduleType::Crontab,
            'schedule_expression' => '0 2 * * *',
            'interval_value' => null,
            'interval_unit' => null,
            'timezone' => 'UTC',
            'checkin_margin_minutes' => 15,
            'max_runtime_minutes' => 30,
            'failure_tolerance' => 1,
            'recovery_tolerance' => 1,
            'is_active' => true,
            'status' => CronMonitorStatus::Unknown,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'last_check_in_at' => null,
            // Bewusst leer: die Fabrik legt den Datensatz an, den Termin setzt
            // `scheduleNextDue()`. Ein hier gesetzter Wert würde in Tests
            // stillschweigend eine Fälligkeit vortäuschen, die zum Zeitplan
            // nicht passt.
            'next_due_at' => null,
            'alerted_at' => null,
        ];
    }

    /**
     * Ein Zeitplan mit festem Abstand statt Cron-Ausdruck.
     */
    public function interval(int $value = 15, CronIntervalUnit $unit = CronIntervalUnit::Minute): self
    {
        return $this->state([
            'schedule_type' => CronScheduleType::Interval,
            'schedule_expression' => null,
            'interval_value' => $value,
            'interval_unit' => $unit,
        ]);
    }

    /**
     * Ein Monitor, dessen Termin bereits läuft — die Ausgangslage fast jeder
     * Prüfung der Ausfallerkennung.
     */
    public function due(): self
    {
        return $this->afterCreating(function (CronMonitor $monitor): void {
            $monitor->scheduleNextDue(now()->subDay());
            $monitor->save();
        });
    }
}
