<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Release;
use App\Support\Releases\Version;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = sprintf(
            '%d.%d.%d',
            fake()->numberBetween(1, 4),
            fake()->numberBetween(0, 20),
            fake()->numberBetween(0, 9),
        );

        $seenAt = Carbon::now()->subDays(fake()->numberBetween(1, 30));

        return [
            'project_id' => Project::factory(),
            // Je Projekt ist die Versionsangabe eindeutig; Tests mit mehreren
            // Versionen am selben Projekt geben sie deshalb selbst an.
            'version' => $version,
            'released_at' => $seenAt,
            'first_event_at' => $seenAt,
            'last_event_at' => $seenAt->copy()->addHours(fake()->numberBetween(1, 48)),
            // Die Sortierfelder gehören zur Versionsangabe und werden nicht
            // frei gewürfelt: eine Zeile, deren Nummer und Sortierung
            // auseinanderlaufen, gibt es im Betrieb nicht, und ein Test darauf
            // würde etwas nachweisen, was nie vorkommt.
            ...Version::parse($version)->columns(),
        ];
    }

    /**
     * Eine Version, wie sie über die Schnittstelle angekündigt wurde: noch ohne
     * ein einziges Ereignis.
     */
    public function announced(): static
    {
        return $this->state([
            'first_event_at' => null,
            'last_event_at' => null,
        ]);
    }

    /**
     * Eine Version mit einer bestimmten Angabe — samt passender Sortierung.
     */
    public function version(string $version): static
    {
        return $this->state([
            'version' => $version,
            ...Version::parse($version)->columns(),
        ]);
    }
}
