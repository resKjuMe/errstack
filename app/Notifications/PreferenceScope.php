<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Project;

/**
 * Geltungsbereich einer persönlichen Einstellung: für alles, für eine
 * Organisation oder für ein einzelnes Projekt.
 *
 * Der Bereich wird als Zeichenkette gespeichert (`global`, `organization:12`,
 * `project:34`) statt als zwei nullbare Fremdschlüssel-Spalten. Grund ist der
 * eindeutige Index: in SQL gelten zwei NULL-Werte als verschieden, ein
 * Index über nullbare Spalten würde denselben Eintrag also beliebig oft
 * zulassen. Die Fremdschlüssel bleiben trotzdem daneben stehen — sie räumen
 * beim Löschen einer Organisation oder eines Projekts auf.
 */
final readonly class PreferenceScope
{
    private function __construct(
        public string $kind,
        public ?int $organizationId,
        public ?int $projectId,
        public string $label,
    ) {}

    public static function global(): self
    {
        return new self('global', null, null, 'Überall');
    }

    public static function forOrganization(Organization $organization): self
    {
        return new self('organization', $organization->id, null, $organization->name);
    }

    /**
     * Ein Projekt-Bereich merkt sich auch die Organisation: er soll
     * mitverschwinden, wenn die Organisation geht, und ohne die Angabe müsste
     * dafür jedes Mal das Projekt nachgeladen werden.
     */
    public static function forProject(Project $project): self
    {
        return new self('project', $project->organization_id, $project->id, $project->name);
    }

    /**
     * Kennung im eindeutigen Index und im Formular.
     */
    public function key(): string
    {
        return match ($this->kind) {
            'organization' => "organization:{$this->organizationId}",
            'project' => "project:{$this->projectId}",
            default => 'global',
        };
    }

    /**
     * Die Bereiche, die für eine Meldung in Frage kommen — vom feinsten zum
     * gröbsten. Genau diese Reihenfolge entscheidet: der erste Bereich mit
     * einer ausdrücklichen Einstellung gewinnt.
     *
     * @return list<string>
     */
    public static function chainFor(?Project $project, ?Organization $organization): array
    {
        $chain = [];

        if ($project !== null) {
            $chain[] = "project:{$project->id}";
        }

        $organizationId = $project?->organization_id ?? $organization?->id;

        if ($organizationId !== null) {
            $chain[] = "organization:{$organizationId}";
        }

        $chain[] = 'global';

        return $chain;
    }
}
