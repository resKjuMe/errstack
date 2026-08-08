<?php

namespace Database\Factories;

use App\Enums\UserReportStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\UserReport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<UserReport>
 */
class UserReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            // Ohne Ereignisbezug: die freie Zuschrift ist der Fall, der ohne
            // weiteres Zutun steht. Wer einen Bezug braucht, nimmt `forEvent()`.
            'event_reference' => null,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'comments' => fake()->sentence(12),
            'url' => 'https://example.test/'.fake()->slug(),
            'status' => UserReportStatus::DEFAULT,
            'received_at' => Carbon::now()->subMinutes(fake()->numberBetween(1, 600)),
        ];
    }

    /**
     * Eine Rückmeldung zu einem bestimmten Ereignis — samt aufgelöstem Bezug.
     */
    public function forEvent(Event $event): self
    {
        return $this->state(fn (): array => [
            'project_id' => $event->project_id,
            'event_reference' => $event->event_id,
            'event_id' => $event->id,
            'issue_id' => $event->group?->issue_id,
        ]);
    }

    public function status(UserReportStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
