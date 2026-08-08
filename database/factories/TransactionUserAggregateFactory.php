<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionUserAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Vorberechnete Nutzer-Zeilen für die Auswertungen.
 *
 * @extends Factory<TransactionUserAggregate>
 */
class TransactionUserAggregateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'name' => 'GET /'.fake()->slug(2),
            'op' => 'http.server',
            'window_start' => Transaction::windowFor(CarbonImmutable::now()),
            'user_key' => TransactionUserAggregate::keyFor((string) fake()->unique()->numberBetween(1, 1_000_000)),
            // Steht hier nur, weil die Spalte nicht leer bleiben darf — den
            // richtigen Wert setzt configure(), wenn alle Bestandteile feststehen.
            'signature' => '',
            'transaction_count' => 1,
            'miserable_count' => 0,
        ];
    }

    public function named(string $name, string $op = 'http.server'): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'op' => $op,
        ]);
    }

    /**
     * Eine Zeile für eine bestimmte Nutzerkennung — für den Fall, auf den es
     * ankommt: derselbe Nutzer in mehreren Fenstern ist **ein** Nutzer.
     */
    public function forUser(string $identifier): static
    {
        return $this->state(fn (): array => [
            'user_key' => TransactionUserAggregate::keyFor($identifier),
        ]);
    }

    public function at(CarbonImmutable|string $when): static
    {
        return $this->state(fn (): array => [
            'window_start' => Transaction::windowFor(
                $when instanceof CarbonImmutable ? $when : CarbonImmutable::parse($when),
            ),
        ]);
    }

    public function inEnvironment(string $environment): static
    {
        return $this->state(fn (): array => ['environment' => $environment]);
    }

    /**
     * Ein Nutzer, dem diese Transaktion zu langsam war.
     */
    public function miserable(int $times = 1): static
    {
        return $this->state(fn (): array => ['miserable_count' => $times]);
    }

    /**
     * Die Signatur ist kein eigener Wert, sondern die Kurzform der vier Spalten,
     * über die die Zeile eindeutig ist. Sie hier abzuleiten statt sie in jedem
     * Test mitzugeben ist mehr als Bequemlichkeit: eine von Hand gesetzte
     * Signatur, die nicht zu den Spalten passt, ergäbe eine Zeile, die es im
     * Betrieb nie gäbe — und der Test bestünde gegen einen Zustand, den die
     * Aufnahme gar nicht erzeugen kann.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (TransactionUserAggregate $aggregate): void {
            $aggregate->signature = TransactionUserAggregate::signatureFor(
                $aggregate->environment,
                $aggregate->name,
                $aggregate->op,
                $aggregate->user_key,
            );
        });
    }
}
