<?php

namespace Database\Factories;

use App\Enums\EventLevel;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['RuntimeException', 'TypeError', 'LogicException']);
        $firstSeen = fake()->dateTimeBetween('-30 days', '-1 day');

        return [
            'project_id' => Project::factory(),
            'title' => $type.': '.fake()->sentence(4),
            'culprit' => 'handle in app/Http/Controllers/ReportController.php',
            'type' => $type,
            'level' => EventLevel::Error,
            'status' => IssueStatus::DEFAULT,
            'priority' => IssuePriority::DEFAULT,
            // Die Zähler bei null: wer einen Eintrag mit Zahlen braucht, lässt
            // sie über die Kette entstehen oder setzt sie ausdrücklich. Ein
            // erfundener Zähler in einer Vorlage sieht in einem Test aus wie ein
            // gerechneter.
            'times_seen' => 0,
            'users_seen' => 0,
            'first_seen' => $firstSeen,
            'last_seen' => $firstSeen,
        ];
    }
}
