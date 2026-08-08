<?php

namespace Database\Factories;

use App\Enums\AlertComparison;
use App\Enums\AlertDirection;
use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Models\MetricAlert;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricAlert>
 */
class MetricAlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Fehlerflut',
            'metric' => AlertMetric::ErrorCount,
            'direction' => AlertDirection::Above,
            'comparison' => AlertComparison::Absolute,
            'environment' => null,
            'transaction_name' => null,
            'window_minutes' => 5,
            'warning_threshold' => null,
            'critical_threshold' => 10.0,
            'resolve_threshold' => null,
            'minimum_samples' => 0,
            'is_active' => true,
            'status' => AlertStatus::Ok,
        ];
    }

    /**
     * Ein Alarm auf einer Antwortzeit-Kennzahl.
     */
    public function metric(AlertMetric $metric): static
    {
        return $this->state(fn (): array => ['metric' => $metric]);
    }

    /**
     * Ein Alarm, der bereits feuert — der Ausgangspunkt jeder Prüfung, in der
     * es um Entspannung oder Entwarnung geht.
     */
    public function firing(AlertStatus $status = AlertStatus::Critical): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'status_since' => now()->subMinutes(10),
        ]);
    }
}
