<?php

namespace Database\Factories;

use App\Enums\HttpMethod;
use App\Enums\UptimeStatus;
use App\Models\Project;
use App\Models\UptimeMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UptimeMonitor>
 */
class UptimeMonitorFactory extends Factory
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
            'url' => 'https://example.test/health',
            'method' => HttpMethod::Get,
            'headers' => null,
            'body' => null,
            'expected_status_codes' => '200-299',
            'expected_content' => null,
            'interval_seconds' => 300,
            'timeout_seconds' => 10,
            // Ohne Bestätigung, weil die Fabrik in Tests der Ausgangspunkt ist:
            // wer die Wiederholung prüfen will, stellt sie ausdrücklich ein
            // ({@see confirming()}) — und alle übrigen Tests warten nicht auf
            // eine zweite Anfrage, die sie nicht gemeint haben.
            'confirmation_retries' => 0,
            'confirmation_delay_seconds' => 0,
            'failure_threshold' => 1,
            'recovery_threshold' => 1,
            'follow_redirects' => true,
            'verify_tls' => true,
            'is_active' => true,
            'status' => UptimeStatus::Unknown,
            'consecutive_failures' => 0,
            'consecutive_successes' => 0,
            'last_checked_at' => null,
            // Bewusst leer: die Fabrik legt den Datensatz an, die Fälligkeit
            // setzt der benannte Konstruktor bzw. der Zustand `due()`. Ein hier
            // gesetzter Wert würde in Tests stillschweigend eine Fälligkeit
            // vortäuschen, die zum Takt nicht passt.
            'next_check_at' => null,
        ];
    }

    /**
     * Ein Monitor, dessen Prüfung ansteht — die Ausgangslage jeder Prüfung des
     * Sweeps.
     */
    public function due(): self
    {
        return $this->state(fn (): array => ['next_check_at' => now()->subMinute()]);
    }

    /**
     * Ein Monitor mit Bestätigungswiederholung, aber ohne Wartezeit dazwischen
     * — die Wartezeit ist in Tests eine Sekunde, die niemandem etwas beweist.
     */
    public function confirming(int $retries = 1): self
    {
        return $this->state(fn (): array => [
            'confirmation_retries' => $retries,
            'confirmation_delay_seconds' => 0,
        ]);
    }

    /**
     * Ein Monitor, der eine Inhaltsprüfung verlangt.
     */
    public function expectingContent(string $text): self
    {
        return $this->state(fn (): array => ['expected_content' => $text]);
    }
}
