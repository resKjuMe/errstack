<?php

namespace Database\Factories;

use App\Models\Commit;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Commit>
 */
class CommitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            // Ein Hash in der Länge, die Git tatsächlich liefert — ein
            // gekürzter würde in Tests über die Anzeige des kurzen Hashes
            // nichts nachweisen.
            'sha' => fake()->unique()->sha1(),
            'message' => fake()->sentence(),
            'author_name' => fake()->name(),
            'author_email' => fake()->unique()->safeEmail(),
            // Kein Konto: die meisten Commits eines Projekts stammen von
            // Personen, die hier keines haben. Die Zuordnung ist der Sonderfall
            // und wird von den Tests ausdrücklich hergestellt.
            'author_id' => null,
            'committed_at' => Carbon::now()->subDays(fake()->numberBetween(1, 30)),
        ];
    }
}
