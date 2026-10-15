<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider' => IntegrationProvider::GitHub,
            'account' => 'acme',
            'external_id' => (string) fake()->unique()->numberBetween(1000, 99999),
            // Das Token steht nicht in `fillable` (siehe Integration) — die
            // Factory setzt es deshalb wie das Modell selbst, über die
            // Attribut-Liste.
            'credentials' => ['token' => 'gho_'.fake()->regexify('[A-Za-z0-9]{32}')],
            'status' => IntegrationStatus::Connected,
        ];
    }

    /**
     * Eine Anbindung, deren Zugang abgelehnt wurde — der Fall, für den es die
     * Anzeige „Verbindung verloren" gibt.
     */
    public function disconnected(): static
    {
        return $this->state([
            'status' => IntegrationStatus::Disconnected,
            'last_error' => 'Bad credentials',
            'last_error_at' => now(),
        ]);
    }

    /**
     * Eine Jira-Anbindung (X4).
     *
     * Sie braucht mehr als ein Token: die Adresse der Instanz (jede Organisation
     * hat ihre eigene) und die E-Mail-Adresse, mit der das Token erzeugt wurde —
     * Jira Cloud will beides zusammen als Basic-Auth.
     */
    public function jira(string $baseUrl = 'https://acme.atlassian.net'): static
    {
        return $this->ticket(IntegrationProvider::Jira, [
            'base_url' => $baseUrl,
            'email' => 'ops@acme.test',
        ]);
    }

    public function linear(): static
    {
        return $this->ticket(IntegrationProvider::Linear);
    }

    /**
     * Das Geheimnis der Rückadresse steht mit, samt seinem Hash: ohne ihn ist der
     * Webhook-Eingang nicht erreichbar — und das ist die halbe Anbindung.
     *
     * @param  array<string, string>  $credentials
     */
    private function ticket(IntegrationProvider $provider, array $credentials = []): static
    {
        $webhookToken = 'wht_'.fake()->regexify('[A-Za-z0-9]{32}');

        return $this->state([
            'provider' => $provider,
            'account' => 'Christian Mietze',
            'credentials' => [
                'token' => fake()->regexify('[A-Za-z0-9]{40}'),
                'webhook_token' => $webhookToken,
                ...$credentials,
            ],
            'webhook_token_hash' => Integration::hashWebhookToken($webhookToken),
        ]);
    }
}
