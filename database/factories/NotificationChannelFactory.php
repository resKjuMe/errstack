<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Notifications\Drivers\DiscordDriver;
use App\Notifications\Drivers\MailDriver;
use App\Notifications\Drivers\SlackDriver;
use App\Notifications\Drivers\TeamsDriver;
use App\Notifications\Drivers\WebhookDriver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /**
     * Standardfall ist der Webhook: er lässt sich in Tests am einfachsten
     * beobachten (eine HTTP-Anfrage, eine Unterschrift).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => WebhookDriver::key(),
            'name' => fake()->unique()->words(2, true),
            'config' => [
                'url' => 'https://example.com/errstack',
                'secret' => 'geheim-geheim-geheim',
            ],
            'is_active' => true,
        ];
    }

    public function mail(string ...$recipients): static
    {
        return $this->state([
            'type' => MailDriver::key(),
            'config' => ['recipients' => $recipients === [] ? ['bereitschaft@example.com'] : array_values($recipients)],
        ]);
    }

    public function slack(): static
    {
        return $this->state([
            'type' => SlackDriver::key(),
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/xxx'],
        ]);
    }

    public function discord(): static
    {
        return $this->state([
            'type' => DiscordDriver::key(),
            'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/xxx'],
        ]);
    }

    public function teams(): static
    {
        return $this->state([
            'type' => TeamsDriver::key(),
            'config' => ['webhook_url' => 'https://example.webhook.office.com/webhookb2/xxx'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
