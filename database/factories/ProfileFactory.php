<?php

namespace Database\Factories;

use App\Models\IngestPayload;
use App\Models\Profile;
use App\Models\Transaction;
use App\Support\Profiling\CallTree;
use App\Support\Profiling\ProfileFrame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tree = self::tree([
            [['handle', 'query'], 30_000_000],
            [['handle', 'render'], 10_000_000],
        ]);

        return [
            // Zuerst die Messung, dann das Projekt aus ihr: ein Profil in einem
            // anderen Projekt als seine Transaktion gibt es im Feld nicht, und
            // ein Test, der gegen so eine Zeile prüft, prüft nichts.
            'transaction_id' => Transaction::factory(),
            'project_id' => static fn (array $attributes): int => (int) Transaction::query()
                ->whereKey($attributes['transaction_id'])
                ->value('project_id'),
            'ingest_payload_id' => null,
            'profile_id' => IngestPayload::freshEventId(),
            'trace_id' => IngestPayload::freshEventId(),
            'transaction_name' => 'GET /'.fake()->slug(2),
            'platform' => 'php',
            'environment' => 'production',
            'release' => null,
            'thread_id' => '1',
            'started_at' => now()->subSeconds(fake()->numberBetween(1, 600)),
            'duration_us' => intdiv($tree->totalNs, 1000),
            'sample_count' => $tree->sampleCount,
            'frames' => $tree->framesToStorage(),
            'tree' => $tree->treeToStorage(),
        ];
    }

    /**
     * Ein Profil an einer bestimmten Messung — mit deren Projekt, Trace und
     * Namen.
     *
     * Der Regelfall in Tests, und deshalb eine eigene Methode: die vier Werte
     * einzeln zu setzen ist die Art von Wiederholung, bei der beim fünften Test
     * einer davon abweicht und die Ansicht dann leer bleibt, ohne dass jemand
     * versteht warum.
     */
    public function forTransaction(Transaction $transaction): static
    {
        return $this->state(fn (): array => [
            'project_id' => $transaction->project_id,
            'transaction_id' => $transaction->id,
            'trace_id' => $transaction->trace_id,
            'transaction_name' => $transaction->name,
            'environment' => $transaction->environment,
            'started_at' => $transaction->started_at,
        ]);
    }

    /**
     * Ein Profil mit einem selbst gewählten Aufrufbaum.
     *
     * @param  list<array{list<string>, int}>  $paths  Je Eintrag: die Funktionsnamen von der Wurzel abwärts und die Zeit in Nanosekunden.
     */
    public function withPaths(array $paths): static
    {
        $tree = self::tree($paths);

        return $this->state(fn (): array => [
            'duration_us' => intdiv($tree->totalNs, 1000),
            'sample_count' => $tree->sampleCount,
            'frames' => $tree->framesToStorage(),
            'tree' => $tree->treeToStorage(),
        ]);
    }

    /**
     * Baut einen Aufrufbaum aus Funktionsnamen.
     *
     * @param  list<array{list<string>, int}>  $paths
     */
    private static function tree(array $paths): CallTree
    {
        $frames = [];
        $index = [];
        $resolved = [];

        foreach ($paths as [$functions, $weightNs]) {
            $stack = [];

            foreach ($functions as $function) {
                if (! isset($index[$function])) {
                    $index[$function] = count($frames);
                    $frames[] = new ProfileFrame(
                        function: $function,
                        module: null,
                        file: 'app/'.$function.'.php',
                        line: 1,
                        inApp: true,
                    );
                }

                $stack[] = $index[$function];
            }

            $resolved[] = [$stack, $weightNs];
        }

        return CallTree::build($frames, $resolved);
    }
}
