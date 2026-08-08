<?php

namespace App\Support\Performance\Detection;

use App\Enums\PerformanceProblem;
use App\Models\PerformanceSetting;
use App\Models\Project;

/**
 * Die geltenden Schwellen eines Projekts — Vorgabe, überschrieben von dem, was
 * eingestellt wurde.
 *
 * Aufgelöst wird **einmal je Ablauf** und nicht je Erkenner: acht Erkenner mal
 * eine Abfrage wären acht Abfragen für eine Handvoll Zahlen, und das bei jedem
 * gespeicherten Aufruf.
 *
 * Die Umrechnung in die Einheiten der Erkennung passiert hier und nur hier —
 * eingegeben werden Millisekunden und Kilobyte ({@see PerformanceProblem}),
 * gerechnet wird in Mikrosekunden und Bytes.
 */
final class Thresholds
{
    /**
     * @param  array<string, bool>  $enabled  Je Muster: läuft der Erkenner?
     * @param  array<string, array<string, int>>  $values  Je Muster die geltenden Schwellen.
     */
    private function __construct(
        private readonly array $enabled,
        private readonly array $values,
    ) {}

    /**
     * Die Schwellen eines Projekts.
     *
     * @param  iterable<PerformanceSetting>|null  $settings  Bereits geladene Einstellungen;
     *                                                       `null` lädt sie selbst.
     */
    public static function forProject(Project $project, ?iterable $settings = null): self
    {
        $settings ??= $project->performanceSettings()->get();

        $overrides = [];
        $enabled = [];

        foreach ($settings as $setting) {
            $overrides[$setting->problem->value] = $setting->thresholds ?? [];
            $enabled[$setting->problem->value] = $setting->is_enabled;
        }

        $values = [];

        foreach (PerformanceProblem::cases() as $problem) {
            $defaults = $problem->defaults();
            $stored = $overrides[$problem->value] ?? [];

            // Über die **Vorgaben** gelaufen und nicht über das Gespeicherte:
            // damit gelten nur Schlüssel, die der Erkenner heute kennt. Ein
            // Muster, dessen Schwellen sich ändern, schleppt sonst für immer
            // die Werte mit, die einmal in der Datenbank gelandet sind — und
            // die Reihenfolge der Felder im Formular hinge daran, in welcher
            // Reihenfolge sie dort stehen.
            $resolved = [];

            foreach ($defaults as $key => $default) {
                $resolved[$key] = array_key_exists($key, $stored) ? (int) $stored[$key] : $default;
            }

            $values[$problem->value] = $resolved;

            $enabled[$problem->value] ??= true;
        }

        return new self($enabled, $values);
    }

    /**
     * Die Vorgaben ohne jede Einstellung — für Tests und für den Vergleich im
     * Einstellungs-Formular.
     */
    public static function defaults(): self
    {
        $values = [];
        $enabled = [];

        foreach (PerformanceProblem::cases() as $problem) {
            $values[$problem->value] = $problem->defaults();
            $enabled[$problem->value] = true;
        }

        return new self($enabled, $values);
    }

    public function isEnabled(PerformanceProblem $problem): bool
    {
        return $this->enabled[$problem->value] ?? true;
    }

    /**
     * Eine Anzahl-Schwelle.
     */
    public function count(PerformanceProblem $problem, string $key = 'min_count'): int
    {
        return $this->raw($problem, $key);
    }

    /**
     * Eine Zeit-Schwelle, umgerechnet in Mikrosekunden.
     */
    public function durationUs(PerformanceProblem $problem, string $key = 'min_duration_ms'): int
    {
        return $this->raw($problem, $key) * 1_000;
    }

    /**
     * Eine Größen-Schwelle, umgerechnet in Bytes.
     *
     * 1024 und nicht 1000: gemeint ist die Größe einer Datei, und die wird
     * überall sonst — im Browser, im Betriebssystem, im Web-Server — ebenso
     * gerechnet.
     */
    public function bytes(PerformanceProblem $problem, string $key = 'min_size_kb'): int
    {
        return $this->raw($problem, $key) * 1024;
    }

    /**
     * Der eingestellte Wert in der Einheit, in der er eingegeben wurde.
     */
    public function raw(PerformanceProblem $problem, string $key): int
    {
        return $this->values[$problem->value][$key] ?? $problem->defaults()[$key] ?? 0;
    }

    /**
     * Alle geltenden Werte eines Musters — für die Anzeige im Formular.
     *
     * @return array<string, int>
     */
    public function all(PerformanceProblem $problem): array
    {
        return $this->values[$problem->value] ?? $problem->defaults();
    }
}
