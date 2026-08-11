<?php

namespace Database\Factories;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Models\Issue;
use App\Models\IssueLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueLink>
 */
class IssueLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 9999);

        return [
            'issue_id' => Issue::factory(),
            'provider' => IntegrationProvider::GitHub,
            'repository' => 'acme/webshop',
            'number' => $number,
            'title' => fake()->sentence(4),
            'url' => 'https://github.com/acme/webshop/issues/'.$number,
            'state' => ExternalIssueState::Open,
            'created_remotely' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(['state' => ExternalIssueState::Closed]);
    }

    /**
     * Eine Verknüpfung mit einem Jira-Vorgang bzw. einer Linear-Aufgabe (X4).
     *
     * `repository` trägt hier den Projekt- bzw. Team-Schlüssel und `external_id`
     * die Kennung, unter der der Anbieter das Ticket führt — ohne sie lässt sich
     * bei Linear nichts ändern.
     */
    public function ticket(IntegrationProvider $provider, string $target = 'OPS', ?int $number = null): static
    {
        return $this->state(function (array $attributes) use ($provider, $target, $number): array {
            // Die Nummer wird hier gesetzt und nicht beim Aufrufer über
            // `create()` nachgeschoben: die Adresse enthält sie, und eine
            // Verknüpfung, deren Link auf ein anderes Ticket zeigt als ihre
            // Nummer, ist als Ausgangslage für einen Test wertlos.
            $number ??= (int) ($attributes['number'] ?? 1);

            return [
                'provider' => $provider,
                'repository' => $target,
                'number' => $number,
                'external_id' => (string) fake()->unique()->numberBetween(10000, 99999),
                'url' => $provider === IntegrationProvider::Jira
                    ? 'https://acme.atlassian.net/browse/'.$target.'-'.$number
                    : 'https://linear.app/acme/issue/'.$target.'-'.$number,
            ];
        });
    }
}
