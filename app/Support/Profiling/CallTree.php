<?php

namespace App\Support\Profiling;

/**
 * Der Aufrufbaum eines Profils: die Rahmentabelle und die Wege, über die
 * Rechenzeit verbraucht wurde.
 *
 * Aus zehntausend Stichproben („in diesem Augenblick lief dieser Aufrufstapel")
 * wird hier eine Aussage („in dieser Funktion steckt ein Drittel der Zeit").
 * Der Weg dahin ist genau ein Handgriff: gleiche Wege zusammenlegen und die
 * Zeit dabei aufaddieren.
 *
 * Zwei Zahlen je Knoten, und die Unterscheidung ist die wichtigste dieser
 * Klasse:
 *
 *   Gesamtzeit  — von hier abwärts, mitsamt allem Aufgerufenen. Sie sagt, wie
 *                 viel ein Ast kostet, und sie ist die Breite des Balkens.
 *   Selbstzeit  — nur in dieser Funktion. Sie sagt, wo tatsächlich gerechnet
 *                 wird.
 *
 * Wer nur die Gesamtzeit ansieht, landet immer bei `main()` — sie ist per
 * Definition die größte und sagt nichts. Wer nur die Selbstzeit ansieht, sieht
 * die eine teure Schleife, aber nicht, wer sie so oft aufruft.
 */
final class CallTree
{
    /**
     * Wie viele Knoten ein Baum haben darf.
     *
     * Die Grenze schützt nicht vor Angreifern — die Größe des Envelope-Elements
     * begrenzt bereits die Aufnahme —, sondern vor dem Regelfall: eine
     * Anwendung mit tiefer Rekursion oder mit Rahmen, die eine Zeilennummer im
     * Funktionsnamen tragen, erzeugt einen Baum, in dem fast jeder Weg
     * einzigartig ist. Was darüber hinausgeht, wird abgeschnitten und gezählt
     * ({@see $droppedNodes}), damit ein unvollständiger Flamegraph als solcher
     * erkennbar bleibt.
     */
    public const MAX_NODES = 20_000;

    /**
     * @param  list<ProfileFrame>  $frames  Die Rahmentabelle; der Baum verweist über den Platz darin.
     * @param  array<int, CallNode>  $roots  Die Wurzeln, nach dem Platz ihres Rahmens.
     * @param  int  $droppedNodes  Wege, die wegen {@see MAX_NODES} nicht mehr aufgenommen wurden.
     */
    private function __construct(
        public readonly array $frames,
        public readonly array $roots,
        public readonly int $totalNs,
        public readonly int $sampleCount,
        public readonly int $droppedNodes,
    ) {}

    public static function empty(): self
    {
        return new self([], [], 0, 0, 0);
    }

    /**
     * Baut den Baum aus den gemessenen Wegen.
     *
     * Ein Weg ist ein Aufrufstapel **von der Wurzel abwärts** und die Zeit, für
     * die er steht. Die Richtung ist keine Geschmacksfrage: die SDKs melden den
     * Stapel andersherum (die gerade laufende Funktion zuerst), und wer das
     * beim Einlesen nicht dreht, bekommt einen Baum, dessen Wurzeln die
     * innersten Funktionen sind. Gedreht wird deshalb einmal, beim Lesen der
     * Meldung ({@see ProfileEvent}) — hier kommt der Stapel bereits in der
     * Reihenfolge an, in der er gebaut wird.
     *
     * @param  list<ProfileFrame>  $frames
     * @param  iterable<array{list<int>, int}>  $paths  Je Eintrag: die Rahmen-Plätze von der Wurzel abwärts und die Zeit in Nanosekunden.
     */
    public static function build(array $frames, iterable $paths, int $maxNodes = self::MAX_NODES): self
    {
        /** @var array<int, CallNode> $roots */
        $roots = [];
        $nodes = 0;
        $dropped = 0;
        $totalNs = 0;
        $samples = 0;

        foreach ($paths as [$stack, $weightNs]) {
            if ($stack === []) {
                continue;
            }

            $samples++;
            $totalNs += $weightNs;
            $parent = null;
            $node = null;

            foreach ($stack as $frame) {
                $existing = $parent === null
                    ? ($roots[$frame] ?? null)
                    : ($parent->children[$frame] ?? null);

                if ($existing === null) {
                    if ($nodes >= $maxNodes) {
                        // Der Weg endet hier. Die Zeit bleibt trotzdem an dem
                        // Knoten hängen, den wir noch haben — sie fällt also
                        // nicht unter den Tisch, sondern wird eine Ebene zu weit
                        // oben als Selbstzeit ausgewiesen. Das ist die
                        // schonendere von zwei falschen Antworten: die andere
                        // wäre ein Flamegraph, dessen Summen nicht mehr
                        // aufgehen.
                        $dropped++;

                        break;
                    }

                    $nodes++;
                }

                $node = $parent === null
                    ? ($roots[$frame] ??= new CallNode($frame))
                    : $parent->child($frame);

                $node->totalNs += $weightNs;
                $parent = $node;
            }

            if ($node !== null) {
                $node->selfNs += $weightNs;
                $node->selfSamples++;
            }
        }

        return new self($frames, $roots, $totalNs, $samples, $dropped);
    }

    /**
     * Legt mehrere Bäume übereinander.
     *
     * Das ist die Antwort auf „zeig mir nicht diesen einen Aufruf, sondern was
     * dieser Endpunkt üblicherweise tut": ein einzelnes Profil kann den
     * Ausreißer erwischt haben, hundert zusammengelegte zeigen das Muster.
     *
     * Verglichen wird über {@see ProfileFrame::key()} und nicht über den Platz
     * in der Rahmentabelle — der ist je Profil ein anderer, weil ihn das SDK in
     * der Reihenfolge des Auftretens vergibt. Zwei Bäume nach Platznummern
     * zusammenzulegen ergäbe Unsinn, und zwar lautlos.
     *
     * @param  iterable<self>  $trees
     */
    public static function merge(iterable $trees, int $maxNodes = self::MAX_NODES): self
    {
        /** @var list<ProfileFrame> $frames */
        $frames = [];
        /** @var array<string, int> $index */
        $index = [];
        /** @var array<int, CallNode> $roots */
        $roots = [];
        $nodes = 0;
        $dropped = 0;
        $totalNs = 0;
        $samples = 0;

        foreach ($trees as $tree) {
            $totalNs += $tree->totalNs;
            $samples += $tree->sampleCount;
            $dropped += $tree->droppedNodes;

            // Die Übersetzungstabelle „Platz in diesem Baum → Platz im
            // gemeinsamen". Einmal je Baum aufgestellt statt je Knoten
            // nachgeschlagen: ein Baum hat tausende Knoten, aber nur hunderte
            // Rahmen.
            $mapping = [];

            foreach ($tree->frames as $position => $frame) {
                $key = $frame->key();

                if (! isset($index[$key])) {
                    $index[$key] = count($frames);
                    $frames[] = $frame;
                }

                $mapping[$position] = $index[$key];
            }

            foreach ($tree->roots as $root) {
                self::mergeNode($root, $mapping, $roots, null, $nodes, $dropped, $maxNodes);
            }
        }

        return new self($frames, $roots, $totalNs, $samples, $dropped);
    }

    /**
     * Legt einen Knoten samt seiner Zweige in den Zielbaum.
     *
     * @param  array<int, int>  $mapping
     * @param  array<int, CallNode>  $roots
     */
    private static function mergeNode(
        CallNode $source,
        array $mapping,
        array &$roots,
        ?CallNode $parent,
        int &$nodes,
        int &$dropped,
        int $maxNodes,
    ): void {
        $frame = $mapping[$source->frame] ?? null;

        if ($frame === null) {
            return;
        }

        $existing = $parent === null ? ($roots[$frame] ?? null) : ($parent->children[$frame] ?? null);

        if ($existing === null) {
            if ($nodes >= $maxNodes) {
                $dropped++;

                return;
            }

            $nodes++;
        }

        $target = $parent === null
            ? ($roots[$frame] ??= new CallNode($frame))
            : $parent->child($frame);

        $target->totalNs += $source->totalNs;
        $target->selfNs += $source->selfNs;
        $target->selfSamples += $source->selfSamples;

        foreach ($source->children as $child) {
            self::mergeNode($child, $mapping, $roots, $target, $nodes, $dropped, $maxNodes);
        }
    }

    /**
     * Die Selbst- und Gesamtzeit je Funktion — die Liste neben dem Flamegraph.
     *
     * Der Flamegraph zeigt Wege, diese Liste zeigt Funktionen: dieselbe
     * Hilfsfunktion, von zwanzig Stellen aus aufgerufen, ist im Bild zwanzig
     * schmale Balken und hier eine Zeile. Erst dadurch fällt auf, dass sie
     * zusammen ein Drittel der Zeit kostet.
     *
     * Bei der Gesamtzeit wird **Rekursion nicht doppelt gezählt**: ruft `a()`
     * sich selbst auf, taucht sie im selben Weg mehrfach auf, und ihre
     * Gesamtzeit enthielte sich selbst noch einmal. Die Zahl könnte dann über
     * hundert Prozent der gemessenen Zeit liegen — gezählt wird deshalb nur das
     * jeweils oberste Vorkommen im Weg. Die Selbstzeit ist davon nicht
     * betroffen: sie wird an jedem Vorkommen einzeln verbraucht.
     *
     * @return list<FunctionTotal>
     */
    public function functions(): array
    {
        /** @var array<string, array{ProfileFrame, int, int, int}> $totals */
        $totals = [];

        foreach ($this->roots as $root) {
            $this->collect($root, [], $totals);
        }

        $functions = array_map(
            static fn (array $entry): FunctionTotal => new FunctionTotal(
                frame: $entry[0],
                selfNs: $entry[1],
                totalNs: $entry[2],
                selfSamples: $entry[3],
            ),
            array_values($totals),
        );

        usort(
            $functions,
            static fn (FunctionTotal $a, FunctionTotal $b): int => $b->selfNs <=> $a->selfNs,
        );

        return $functions;
    }

    /**
     * @param  array<string, true>  $path  Die Funktionen, die auf dem Weg hierher schon vorkamen.
     * @param  array<string, array{ProfileFrame, int, int, int}>  $totals
     */
    private function collect(CallNode $node, array $path, array &$totals): void
    {
        $frame = $this->frames[$node->frame] ?? null;

        if ($frame === null) {
            return;
        }

        $key = $frame->key();
        $entry = $totals[$key] ?? [$frame, 0, 0, 0];

        $entry[1] += $node->selfNs;
        $entry[3] += $node->selfSamples;

        if (! isset($path[$key])) {
            $entry[2] += $node->totalNs;
        }

        $totals[$key] = $entry;
        $path[$key] = true;

        foreach ($node->children as $child) {
            $this->collect($child, $path, $totals);
        }
    }

    /**
     * Die Rahmentabelle, wie sie in der Spalte `frames` steht.
     *
     * @return list<array<string, mixed>>
     */
    public function framesToStorage(): array
    {
        return array_map(
            static fn (ProfileFrame $frame): array => $frame->toStorage(),
            $this->frames,
        );
    }

    /**
     * Der Baum, wie er in der Spalte `tree` steht.
     *
     * Die Schlüssel sind einbuchstabig, und das ist hier keine Sparsamkeit um
     * ihrer selbst willen: der Baum hat bis zu {@see MAX_NODES} Knoten, und
     * `self_ns` statt `s` wäre bei zwanzigtausend Knoten allein an
     * Feldnamen ein knappes Megabyte je Zeile. Gelesen wird die Form nur an
     * zwei Stellen — hier und in {@see fromStorage()}.
     *
     * @return array<string, mixed>
     */
    public function treeToStorage(): array
    {
        return [
            'total_ns' => $this->totalNs,
            'samples' => $this->sampleCount,
            'dropped' => $this->droppedNodes,
            'roots' => array_map(
                static fn (CallNode $node): array => self::nodeToStorage($node),
                array_values($this->roots),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nodeToStorage(CallNode $node): array
    {
        $stored = [
            'f' => $node->frame,
            's' => $node->selfNs,
            't' => $node->totalNs,
            'n' => $node->selfSamples,
        ];

        if ($node->children !== []) {
            $stored['c'] = array_map(
                static fn (CallNode $child): array => self::nodeToStorage($child),
                array_values($node->children),
            );
        }

        return $stored;
    }

    /**
     * Liest den Baum aus der Ablage zurück.
     *
     * Nachsichtig gegenüber allem, was fehlt oder nicht passt: die Spalten sind
     * JSON, und JSON kann alles enthalten. Ein Knoten ohne Rahmen-Verweis wird
     * übergangen, statt die ganze Seite scheitern zu lassen — eine Zeile, die
     * sich nicht mehr lesen lässt, ist ein Profil weniger und kein Ausfall der
     * Auswertung.
     *
     * @param  array<mixed>  $frames
     * @param  array<mixed>  $tree
     */
    public static function fromStorage(array $frames, array $tree): self
    {
        $table = [];

        foreach ($frames as $frame) {
            if (is_array($frame)) {
                $table[] = ProfileFrame::fromStorage($frame);
            }
        }

        $roots = [];
        $raw = $tree['roots'] ?? null;

        if (is_array($raw)) {
            foreach ($raw as $node) {
                $restored = is_array($node) ? self::nodeFromStorage($node) : null;

                if ($restored !== null) {
                    $roots[$restored->frame] = $restored;
                }
            }
        }

        return new self(
            frames: $table,
            roots: $roots,
            totalNs: is_int($tree['total_ns'] ?? null) ? $tree['total_ns'] : 0,
            sampleCount: is_int($tree['samples'] ?? null) ? $tree['samples'] : 0,
            droppedNodes: is_int($tree['dropped'] ?? null) ? $tree['dropped'] : 0,
        );
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function nodeFromStorage(array $raw): ?CallNode
    {
        $frame = $raw['f'] ?? null;

        if (! is_int($frame)) {
            return null;
        }

        $node = new CallNode($frame);
        $node->selfNs = is_int($raw['s'] ?? null) ? $raw['s'] : 0;
        $node->totalNs = is_int($raw['t'] ?? null) ? $raw['t'] : 0;
        $node->selfSamples = is_int($raw['n'] ?? null) ? $raw['n'] : 0;

        $children = $raw['c'] ?? null;

        if (is_array($children)) {
            foreach ($children as $child) {
                $restored = is_array($child) ? self::nodeFromStorage($child) : null;

                if ($restored !== null) {
                    $node->children[$restored->frame] = $restored;
                }
            }
        }

        return $node;
    }
}
