<?php

namespace Database\Factories;

use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\Dashboards\DashboardLayout;
use App\Support\Discover\Dataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'title' => 'Kachel '.fake()->unique()->numberBetween(1, 100000),
            'type' => WidgetType::Line,
            'query' => [
                'dataset' => Dataset::Errors->value,
                'fields' => [],
                'metrics' => ['count()'],
                'q' => '',
                'sort' => '',
                'limit' => 5,
                'interval' => null,
            ],
            'overrides' => null,
            'x' => 0,
            'y' => 0,
            'width' => DashboardLayout::DEFAULT_WIDTH,
            'height' => DashboardLayout::DEFAULT_HEIGHT,
        ];
    }

    public function ofType(WidgetType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }
}
