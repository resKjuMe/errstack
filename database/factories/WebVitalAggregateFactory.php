<?php

namespace Database\Factories;

use App\Enums\WebVital;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\WebVitalAggregate;
use App\Support\Performance\Vitals\VitalHistogram;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Vorberechnete Zeitfenster der Browser-Messwerte.
 *
 * Aus demselben Grund wie bei den Antwortzeiten: eine Messung durch die ganze
 * Verarbeitungskette zu schicken, nur damit am Ende eine Zahl in einem Fenster
 * steht, prüft die Kette und nicht die Auswertung.
 *
 * @extends Factory<WebVitalAggregate>
 */
class WebVitalAggregateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Ein plausibles LCP: zwischen einer halben und vier Sekunden bewegen
        // sich echte Ladezeiten.
        return $this->measuringState(
            WebVital::Lcp,
            fake()->numberBetween(500_000, 4_000_000),
            fake()->numberBetween(1, 50),
        ) + [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'name' => '/'.fake()->slug(2),
            'window_start' => Transaction::windowFor(CarbonImmutable::now()),
        ];
    }

    /**
     * Ein Fenster mit lauter gleich großen Messungen eines Messwerts.
     *
     * Der Regelfall für Tests über Perzentile und Bewertung: liegen alle
     * Messungen in derselben Klasse, ist jedes Perzentil deren Schätzwert — und
     * damit vorhersagbar, ohne die Rechnung der Verteilung nachzubauen.
     *
     * @param  int  $value  In Millionsteln ({@see WebVital}).
     */
    public function measuring(WebVital $vital, int $value, int $count = 1): static
    {
        return $this->state(fn (): array => $this->measuringState($vital, $value, $count));
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }

    /**
     * Verschiebt das Fenster. Der Zeitpunkt wird auf das Minutenraster
     * abgeschnitten, wie es die Aufnahme täte.
     */
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
     * Die Felder einer Zeile aus lauter gleich großen Messungen.
     *
     * Die Bewertung wird hier mit demselben Aufruf gebildet, den auch die
     * Aufnahme benutzt ({@see WebVital::rate()}) — eine im Test hingeschriebene
     * Einteilung könnte von der echten abweichen und würde dann eine falsche
     * Anzeige bestätigen.
     *
     * @return array<string, mixed>
     */
    private function measuringState(WebVital $vital, int $value, int $count): array
    {
        $counts = [
            'good_count' => 0,
            'needs_improvement_count' => 0,
            'poor_count' => 0,
        ];

        $counts[$vital->rate($value)->column()] = $count;

        return $counts + [
            'vital' => $vital->value,
            'measurement_count' => $count,
            'value_sum' => $value * $count,
            'value_min' => $value,
            'value_max' => $value,
            'value_histogram' => [VitalHistogram::bucketFor($value) => $count],
        ];
    }
}
