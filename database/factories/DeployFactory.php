<?php

namespace Database\Factories;

use App\Models\Deploy;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Deploy>
 */
class DeployFactory extends Factory
{
    /**
     * Die drei Bezüge einer Auslieferung gehören zum **selben** Projekt.
     *
     * Sie einzeln würfeln zu lassen wäre bequemer und falsch: es entstünde ein
     * Deploy, dessen Version zu einem anderen Projekt gehört als seine
     * Umgebung — ein Zustand, den {@see Deploy::record()} nicht erzeugen kann.
     * Ein Test darauf wiese etwas nach, was im Betrieb nicht vorkommt. Deshalb
     * gibt die Version den Ton an, und Projekt und Umgebung folgen ihr.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'release_id' => Release::factory(),
            'project_id' => fn (array $attributes): int => self::release($attributes)->project_id,
            'environment_id' => fn (array $attributes): int => Environment::factory()
                ->create(['project_id' => self::release($attributes)->project_id])->id,
            'name' => null,
            'url' => null,
            'started_at' => null,
            'finished_at' => Carbon::now()->subHours(fake()->numberBetween(1, 72)),
        ];
    }

    /**
     * Eine Auslieferung dieser Version in diese Umgebung.
     */
    public function of(Release $release, ?string $environment = null): static
    {
        return $this->state(fn (): array => [
            'release_id' => $release->id,
            'project_id' => $release->project_id,
            'environment_id' => Environment::forName(
                Project::query()->findOrFail($release->project_id),
                $environment,
            )->id,
        ]);
    }

    /**
     * Die Version, an der ein noch unfertiger Datensatz hängt.
     *
     * Beide Formen, weil die Auflösung der Vorgaben je nach Aufruf entweder das
     * Modell selbst oder nur seine Kennung stehen lässt.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function release(array $attributes): Release
    {
        $release = $attributes['release_id'] ?? null;

        return $release instanceof Release
            ? $release
            : Release::query()->findOrFail($release);
    }
}
