<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\ResolutionBehavior;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            // Der Slug ist nur je Organisation eindeutig; in Tests entstehen
            // Projekte oft ohne Umweg über createFor(), deshalb hier ein
            // eigener eindeutiger Wert statt der Nummerierung des Models.
            'slug' => Str::slug($name),
            'platform' => fake()->randomElement(Platform::cases()),
            'default_environment' => 'production',
            'resolution_behavior' => ResolutionBehavior::Manual,
            'retention_days' => 30,
        ];
    }

    /**
     * Wie beim Anlegen über die Oberfläche entsteht der erste Client-Schlüssel
     * mit — sonst hätte ein Projekt aus der Factory keine DSN und verhielte
     * sich anders als jedes echte.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            ProjectKey::createFor($project, Project::FIRST_KEY_NAME);
        });
    }
}
