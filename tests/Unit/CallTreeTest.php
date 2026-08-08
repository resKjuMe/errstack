<?php

namespace Tests\Unit;

use App\Support\Profiling\CallTree;
use App\Support\Profiling\FunctionTotal;
use App\Support\Profiling\ProfileEvent;
use App\Support\Profiling\ProfileFrame;
use PHPUnit\Framework\TestCase;

/**
 * Der Aufrufbaum: Selbst- und Gesamtzeit, Zusammenlegen, Rekursion.
 *
 * Ein reiner Rechentest ohne Datenbank. Was hier schiefgeht, geht in **jedem**
 * Flamegraph schief — und zwar unauffällig: ein Bild aus falschen Breiten sieht
 * genauso aus wie eines aus richtigen.
 */
class CallTreeTest extends TestCase
{
    /**
     * Die Funktionsliste nach Namen, damit die Prüfungen unten lesbar bleiben.
     *
     * @return array<string, FunctionTotal>
     */
    private function byName(CallTree $tree): array
    {
        $functions = [];

        foreach ($tree->functions() as $function) {
            $functions[$function->frame->function] = $function;
        }

        return $functions;
    }

    /**
     * @param  list<string>  $names
     * @return list<ProfileFrame>
     */
    private function frames(array $names): array
    {
        return array_map(
            static fn (string $name): ProfileFrame => new ProfileFrame(
                function: $name,
                module: null,
                file: 'app/'.$name.'.php',
                line: 1,
                inApp: true,
            ),
            $names,
        );
    }

    public function test_self_time_stays_at_the_leaf_and_total_time_adds_up(): void
    {
        // 0 = handle, 1 = query, 2 = render
        $tree = CallTree::build($this->frames(['handle', 'query', 'render']), [
            [[0, 1], 20],
            [[0, 2], 10],
        ]);

        $functions = $this->byName($tree);

        $this->assertSame(30, $tree->totalNs);
        $this->assertSame(0, $functions['handle']->selfNs);
        $this->assertSame(30, $functions['handle']->totalNs);
        $this->assertSame(20, $functions['query']->selfNs);
        $this->assertSame(20, $functions['query']->totalNs);
    }

    public function test_recursion_is_not_counted_twice_in_the_total(): void
    {
        // `walk` ruft sich selbst auf: 0 → 0 → 1. Ohne Vorkehrung stünde die
        // Gesamtzeit von `walk` zweimal in der Summe — die Funktion käme auf
        // 200 % der gemessenen Zeit.
        $tree = CallTree::build($this->frames(['walk', 'visit']), [
            [[0, 0, 1], 10],
        ]);

        $functions = $this->byName($tree);

        $this->assertSame(10, $tree->totalNs);
        $this->assertSame(10, $functions['walk']->totalNs);
        $this->assertSame(0, $functions['walk']->selfNs);
        $this->assertSame(10, $functions['visit']->selfNs);
    }

    public function test_merging_matches_functions_and_not_positions(): void
    {
        // Zwei Bäume mit **vertauschter** Rahmentabelle. Nach Platznummern
        // zusammengelegt ergäbe das Unsinn, und zwar lautlos.
        $left = CallTree::build($this->frames(['handle', 'query']), [[[0, 1], 10]]);
        $right = CallTree::build($this->frames(['query', 'handle']), [[[1, 0], 30]]);

        $merged = CallTree::merge([$left, $right]);
        $functions = $this->byName($merged);

        $this->assertSame(40, $merged->totalNs);
        $this->assertSame(40, $functions['query']->selfNs);
        $this->assertSame(0, $functions['handle']->selfNs);
        $this->assertCount(1, $merged->roots);
    }

    public function test_a_stored_tree_comes_back_unchanged(): void
    {
        $tree = CallTree::build($this->frames(['handle', 'query']), [
            [[0, 1], 25],
            [[0], 5],
        ]);

        $restored = CallTree::fromStorage($tree->framesToStorage(), $tree->treeToStorage());

        $this->assertSame($tree->totalNs, $restored->totalNs);
        $this->assertSame($tree->sampleCount, $restored->sampleCount);
        $this->assertEquals(
            array_map(fn ($f): array => $f->toArray(), $tree->functions()),
            array_map(fn ($f): array => $f->toArray(), $restored->functions()),
        );
    }

    public function test_a_tree_stops_growing_at_its_limit_and_says_so(): void
    {
        // Drei Wege mit je zwei neuen Knoten, aber nur Platz für drei Knoten.
        $tree = CallTree::build($this->frames(['a', 'b', 'c', 'd']), [
            [[0, 1], 10],
            [[2, 3], 10],
        ], maxNodes: 3);

        $this->assertSame(20, $tree->totalNs);
        $this->assertSame(1, $tree->droppedNodes);
    }

    public function test_a_sample_profile_becomes_a_tree(): void
    {
        // Der Weg, den eine echte Meldung nimmt — samt der beiden Stolpersteine:
        // die Stapel stehen von innen nach außen, und das Gewicht einer
        // Stichprobe ist der Abstand zur nächsten.
        $event = ProfileEvent::fromPayload([
            'version' => '1',
            'transaction' => ['id' => str_repeat('b', 32), 'active_thread_id' => '1'],
            'profile' => [
                'frames' => [
                    ['function' => 'query', 'filename' => 'app/query.php'],
                    ['function' => 'handle', 'filename' => 'app/handle.php'],
                ],
                'stacks' => [[0, 1]],
                'samples' => [
                    ['stack_id' => 0, 'thread_id' => '1', 'elapsed_since_start_ns' => 0],
                    ['stack_id' => 0, 'thread_id' => '1', 'elapsed_since_start_ns' => 10_000_000],
                ],
            ],
        ], str_repeat('c', 32));

        $this->assertNotNull($event);
        $this->assertSame(20_000, $event->durationUs);

        $root = $event->tree->roots[array_key_first($event->tree->roots)];

        // Die Wurzel ist `handle` und nicht `query`: der gemeldete Stapel steht
        // andersherum.
        $this->assertSame('handle', $event->tree->frames[$root->frame]->function);
    }
}
