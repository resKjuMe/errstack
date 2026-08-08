<?php

namespace App\Support\Performance\Trace;

use App\Models\Event;
use App\Models\Transaction;
use App\Models\TransactionSpan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Der Ablauf eines Aufrufs über alle beteiligten Dienste: aus einer
 * Spur-Kennung wird der Baum, den der Wasserfall zeigt.
 *
 * **Die Spur ist projektübergreifend, und das ist ihr ganzer Zweck.** Ein
 * Seitenaufruf im Browser ruft ein Backend, das eine zweite Anwendung ruft — drei
 * Projekte, drei Transaktionen, eine `trace_id`. Deshalb steht in keiner Abfrage
 * hier ein einzelnes Projekt, sondern die Menge der Projekte, die der Betrachter
 * sehen darf. Fehlt eines davon (fremde Organisation, nicht angebundener
 * Dienst), verschwindet sein Teil nicht stillschweigend: die Kinder verweisen
 * dann auf einen Elternteil, den es hier nicht gibt, und daraus wird eine
 * sichtbare Lücke ({@see TraceNode::KIND_MISSING}).
 *
 * **Feste Zahl an Abfragen**, unabhängig von der Größe der Spur: die
 * Transaktionen, ihre Schritte, die Fehler — mehr wird nicht gelesen. Ein Baum
 * mit tausend Schritten entsteht in PHP aus dem, was in diesen drei Abfragen
 * ankam, und nicht durch Nachschlagen je Ebene; die Tiefe einer Spur ist nicht
 * vorhersagbar, und eine Abfrage je Ebene wäre bei einer tiefen Spur eine
 * Abfrage je Schritt.
 */
final class TraceView
{
    /**
     * Wie viele Transaktionen einer Spur überhaupt geladen werden.
     *
     * Eine Spur mit mehr als tausend Diensten gibt es nicht; was sie erzeugt,
     * ist eine falsch gesetzte Kennung — etwa ein SDK, das für einen ganzen
     * Warteschlangen-Arbeiter eine einzige `trace_id` führt. Die Grenze macht
     * daraus eine unvollständige Ansicht mit Hinweis statt einer Seite, die
     * nicht mehr aufgeht.
     */
    public const TRANSACTION_LIMIT = 1000;

    /**
     * Wie viele Einzelschritte geladen werden.
     *
     * Zehntausend, also das Zehnfache dessen, was die Aufgabe als bedienbar
     * verlangt. Die Zahl ist nicht die Grenze der Anzeige — die zeigt nur, was
     * im Fenster steht —, sondern die des Speichers auf dem Server.
     */
    public const SPAN_LIMIT = 10000;

    /** Wie viele Fehler einer Spur gezeigt werden. */
    public const ERROR_LIMIT = 500;

    /**
     * @param  list<TraceNode>  $roots
     * @param  list<TraceError>  $unassignedErrors  Fehler ohne zuordenbaren Schritt
     * @param  list<array{name: string, slug: string, transactions: int}>  $services
     */
    private function __construct(
        public readonly string $traceId,
        public readonly array $roots,
        public readonly array $unassignedErrors,
        public readonly array $services,
        public readonly ?CarbonImmutable $startedAt,
        public readonly int $durationUs,
        public readonly int $transactionCount,
        public readonly int $spanCount,
        public readonly int $errorCount,
        public readonly bool $truncated,
    ) {}

    /**
     * Lädt eine Spur und baut ihren Baum.
     *
     * @param  list<int>  $projectIds  Projekte, die der Betrachter sehen darf
     */
    public static function load(string $traceId, array $projectIds): self
    {
        if ($projectIds === []) {
            return self::empty($traceId);
        }

        $transactions = Transaction::query()
            ->select(['id', 'project_id', 'trace_id', 'span_id', 'parent_span_id', 'name', 'op', 'status', 'started_at', 'finished_at', 'duration_us'])
            ->with('project:id,name,slug')
            ->where('trace_id', $traceId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('started_at')
            ->orderBy('id')
            // Eine Zeile über der Grenze ist der Beleg, dass abgeschnitten
            // wurde — ohne eine zweite Abfrage, die nur zählt.
            ->limit(self::TRANSACTION_LIMIT + 1)
            ->get();

        $truncated = $transactions->count() > self::TRANSACTION_LIMIT;
        $transactions = $transactions->take(self::TRANSACTION_LIMIT);

        if ($transactions->isEmpty()) {
            return self::empty($traceId);
        }

        $spans = TransactionSpan::query()
            ->select(['id', 'transaction_id', 'project_id', 'span_id', 'parent_span_id', 'op', 'description', 'status', 'started_at', 'finished_at', 'duration_us', 'position'])
            ->where('trace_id', $traceId)
            ->whereIn('transaction_id', $transactions->pluck('id')->all())
            ->orderBy('started_at')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::SPAN_LIMIT + 1)
            ->get();

        $truncated = $truncated || $spans->count() > self::SPAN_LIMIT;
        $spans = $spans->take(self::SPAN_LIMIT);

        $errors = Event::query()
            ->with('group:id,issue_id')
            ->where('trace_id', $traceId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::ERROR_LIMIT + 1)
            ->get();

        $truncated = $truncated || $errors->count() > self::ERROR_LIMIT;
        $errors = $errors->take(self::ERROR_LIMIT);

        return self::build($traceId, $transactions, $spans, $errors, $truncated);
    }

    /**
     * Setzt aus den drei Ergebnismengen den Baum zusammen.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  Collection<int, TransactionSpan>  $spans
     * @param  Collection<int, Event>  $errors
     */
    private static function build(string $traceId, Collection $transactions, Collection $spans, Collection $errors, bool $truncated): self
    {
        /** @var array<string, TraceNode> $index */
        $index = [];

        /** @var list<TraceNode> $nodes */
        $nodes = [];

        foreach ($transactions as $transaction) {
            $node = new TraceNode(
                kind: TraceNode::KIND_TRANSACTION,
                spanId: $transaction->span_id,
                parentSpanId: $transaction->parent_span_id,
                label: $transaction->name,
                op: $transaction->op,
                status: $transaction->status,
                startedAt: $transaction->started_at,
                finishedAt: $transaction->finished_at,
                durationUs: $transaction->duration_us,
                projectName: $transaction->project?->name,
                projectSlug: $transaction->project?->slug,
                transactionId: $transaction->id,
            );

            $nodes[] = $node;
            self::index($index, $node);
        }

        /** @var array<int, Transaction> $byId */
        $byId = $transactions->keyBy('id')->all();

        foreach ($spans as $span) {
            $transaction = $byId[$span->transaction_id] ?? null;

            $node = new TraceNode(
                kind: TraceNode::KIND_SPAN,
                spanId: $span->span_id,
                parentSpanId: $span->parent_span_id,
                label: $span->description,
                op: $span->op,
                status: $span->status,
                startedAt: $span->started_at,
                finishedAt: $span->finished_at,
                durationUs: $span->duration_us,
                projectName: $transaction?->project?->name,
                projectSlug: $transaction?->project?->slug,
                transactionId: $span->transaction_id,
            );

            $nodes[] = $node;
            self::index($index, $node);
        }

        $roots = self::link($nodes, $index);
        $unassigned = self::attachErrors($index, $errors);

        // Die Lücken haben keine eigene Messung; ihre Spanne ist die ihrer
        // Kinder. Deshalb erst jetzt, wenn die Kinder hängen.
        foreach ($roots as $root) {
            self::span($root);
        }

        self::sort($roots);

        $started = self::earliest($roots);
        $finished = self::latest($roots);

        return new self(
            traceId: $traceId,
            roots: $roots,
            unassignedErrors: $unassigned,
            services: self::services($transactions),
            startedAt: $started,
            durationUs: $started !== null && $finished !== null
                ? max(0, TraceNode::micros($finished) - TraceNode::micros($started))
                : 0,
            transactionCount: $transactions->count(),
            spanCount: $spans->count(),
            errorCount: $errors->count(),
            truncated: $truncated,
        );
    }

    /**
     * Trägt einen Knoten unter seiner Span-Kennung ein — der erste gewinnt.
     *
     * Kennungen sind je Spur eindeutig gedacht, aber nicht erzwungen: ein SDK
     * mit schwachem Zufall oder ein zweimal gemeldeter Schritt erzeugt sie
     * doppelt. Der zweite Knoten bleibt sichtbar (er steht in `$nodes`), taugt
     * aber nicht als Elternteil — sonst hinge dieselbe Kindmenge zweimal im
     * Baum, und aus einer Spur mit dreißig Schritten würden je nach Tiefe
     * einige Tausend.
     *
     * @param  array<string, TraceNode>  $index
     */
    private static function index(array &$index, TraceNode $node): void
    {
        if ($node->spanId !== null && ! isset($index[$node->spanId])) {
            $index[$node->spanId] = $node;
        }
    }

    /**
     * Hängt jeden Knoten unter seinen Elternteil und liefert die Wurzeln.
     *
     * @param  list<TraceNode>  $nodes
     * @param  array<string, TraceNode>  $index
     * @return list<TraceNode>
     */
    private static function link(array $nodes, array &$index): array
    {
        /** @var list<TraceNode> $roots */
        $roots = [];

        foreach ($nodes as $node) {
            if ($node->parentSpanId === null) {
                $roots[] = $node;

                continue;
            }

            $parent = $index[$node->parentSpanId] ?? null;

            if ($parent === null) {
                // Der genannte Elternteil fehlt. Statt den Knoten an die Wurzel
                // zu hängen — was behaupten würde, er habe keinen — entsteht
                // die Lücke als eigener Knoten. Alle Waisen desselben fehlenden
                // Elternteils finden darunter zusammen.
                $parent = new TraceNode(
                    kind: TraceNode::KIND_MISSING,
                    spanId: $node->parentSpanId,
                    parentSpanId: null,
                    label: null,
                    op: null,
                    status: null,
                );

                $index[$node->parentSpanId] = $parent;
                $roots[] = $parent;
            }

            if ($parent === $node) {
                // Ein Schritt, der sich selbst als Elternteil nennt. Kommt vor,
                // wenn ein SDK die Kennung des Elternteils nicht kennt und die
                // eigene einsetzt — als Kind seiner selbst wäre er unsichtbar.
                $roots[] = $node;

                continue;
            }

            $parent->children[] = $node;
        }

        return self::withoutCycles($roots, $nodes, $index);
    }

    /**
     * Holt zurück, was in einem Ring hängt.
     *
     * Zwei Schritte, die sich gegenseitig als Elternteil nennen, hängen an
     * keiner Wurzel — sie wären in der Anzeige schlicht verschwunden, und die
     * Ansicht behauptete damit eine vollständige Spur, in der etwas fehlt.
     * Gefunden wird das nicht über eine Ringsuche, sondern über den Vergleich:
     * was der Durchlauf von den Wurzeln aus nicht erreicht, ist genau die Menge.
     *
     * Der Ring wird dabei **aufgetrennt** und nicht nur ergänzt: der Knoten wird
     * aus seinem Elternteil gelöst, bevor er Wurzel wird. Ohne das bliebe er
     * zugleich Kind und Wurzel, und der Durchlauf durch den Baum ({@see rows()})
     * liefe im Kreis.
     *
     * @param  list<TraceNode>  $roots
     * @param  list<TraceNode>  $nodes
     * @param  array<string, TraceNode>  $index
     * @return list<TraceNode>
     */
    private static function withoutCycles(array $roots, array $nodes, array $index): array
    {
        /** @var \SplObjectStorage<TraceNode, null> $seen */
        $seen = new \SplObjectStorage;

        foreach ($roots as $root) {
            self::mark($root, $seen);
        }

        foreach ($nodes as $node) {
            if ($seen->contains($node)) {
                continue;
            }

            $parent = $node->parentSpanId === null ? null : ($index[$node->parentSpanId] ?? null);

            if ($parent !== null) {
                $parent->children = array_values(array_filter(
                    $parent->children,
                    fn (TraceNode $child): bool => $child !== $node,
                ));
            }

            $roots[] = $node;
            self::mark($node, $seen);
        }

        return $roots;
    }

    /**
     * @param  \SplObjectStorage<TraceNode, null>  $seen
     */
    private static function mark(TraceNode $node, \SplObjectStorage $seen): void
    {
        if ($seen->contains($node)) {
            return;
        }

        $seen->attach($node);

        foreach ($node->children as $child) {
            self::mark($child, $seen);
        }
    }

    /**
     * Hängt die Fehler an die Schritte, in denen sie gemeldet wurden.
     *
     * @param  array<string, TraceNode>  $index
     * @param  Collection<int, Event>  $errors
     * @return list<TraceError> Fehler ohne zuordenbaren Schritt
     */
    private static function attachErrors(array $index, Collection $errors): array
    {
        /** @var list<TraceError> $unassigned */
        $unassigned = [];

        foreach ($errors as $event) {
            $error = TraceError::fromEvent($event);
            $node = $error->spanId === null ? null : ($index[$error->spanId] ?? null);

            if ($node === null) {
                // Der Fehler gehört zur Spur, nennt aber keinen Schritt oder
                // einen, den wir nicht haben. Er wird trotzdem gezeigt — über
                // dem Wasserfall statt darin.
                $unassigned[] = $error;

                continue;
            }

            $node->errors[] = $error;
        }

        return $unassigned;
    }

    /**
     * Gibt einer Lücke die Zeitspanne ihrer Kinder.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private static function span(TraceNode $node): array
    {
        $start = $node->startedAt;
        $end = $node->finishedAt;

        foreach ($node->children as $child) {
            [$childStart, $childEnd] = self::span($child);

            if ($childStart !== null && ($start === null || $childStart->lessThan($start))) {
                $start = $childStart;
            }

            if ($childEnd !== null && ($end === null || $childEnd->greaterThan($end))) {
                $end = $childEnd;
            }
        }

        if ($node->kind === TraceNode::KIND_MISSING) {
            $node->startedAt = $start;
            $node->finishedAt = $end;
            $node->durationUs = $start !== null && $end !== null
                ? max(0, TraceNode::micros($end) - TraceNode::micros($start))
                : 0;
        }

        return [$start, $end];
    }

    /**
     * Sortiert Geschwister nach ihrem Anfang.
     *
     * Nachrangig nach Kennung, weil gleichzeitig gestartete Schritte sonst bei
     * jedem Aufruf in anderer Reihenfolge stünden — eine Ansicht, die sich beim
     * Neuladen umsortiert, ist beim Vergleichen zweier Aufrufe wertlos.
     *
     * @param  list<TraceNode>  $nodes
     */
    private static function sort(array &$nodes): void
    {
        usort($nodes, function (TraceNode $a, TraceNode $b): int {
            $left = $a->startedAt === null ? PHP_INT_MAX : TraceNode::micros($a->startedAt);
            $right = $b->startedAt === null ? PHP_INT_MAX : TraceNode::micros($b->startedAt);

            return $left <=> $right ?: strcmp($a->key(), $b->key());
        });

        foreach ($nodes as $node) {
            self::sort($node->children);
        }
    }

    /**
     * @param  list<TraceNode>  $nodes
     */
    private static function earliest(array $nodes): ?CarbonImmutable
    {
        $earliest = null;

        foreach ($nodes as $node) {
            if ($node->startedAt !== null && ($earliest === null || $node->startedAt->lessThan($earliest))) {
                $earliest = $node->startedAt;
            }
        }

        return $earliest;
    }

    /**
     * @param  list<TraceNode>  $nodes
     */
    private static function latest(array $nodes): ?CarbonImmutable
    {
        $latest = null;

        foreach ($nodes as $node) {
            if ($node->finishedAt !== null && ($latest === null || $node->finishedAt->greaterThan($latest))) {
                $latest = $node->finishedAt;
            }
        }

        return $latest;
    }

    /**
     * Die beteiligten Dienste — ein Projekt ist hier ein Dienst.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array{name: string, slug: string, transactions: int}>
     */
    private static function services(Collection $transactions): array
    {
        /** @var array<string, array{name: string, slug: string, transactions: int}> $services */
        $services = [];

        foreach ($transactions as $transaction) {
            $project = $transaction->project;

            if ($project === null) {
                continue;
            }

            $services[$project->slug] ??= ['name' => $project->name, 'slug' => $project->slug, 'transactions' => 0];
            $services[$project->slug]['transactions']++;
        }

        ksort($services);

        return array_values($services);
    }

    private static function empty(string $traceId): self
    {
        return new self(
            traceId: $traceId,
            roots: [],
            unassignedErrors: [],
            services: [],
            startedAt: null,
            durationUs: 0,
            transactionCount: 0,
            spanCount: 0,
            errorCount: 0,
            truncated: false,
        );
    }

    /**
     * Der Baum als flache Liste in der Reihenfolge, in der er dasteht.
     *
     * Flach und nicht verschachtelt, weil die Oberfläche bei tausend Schritten
     * nur das zeichnet, was im Fenster steht — und dafür muss sie die Zeile Nr.
     * 800 finden können, ohne den Baum zu durchlaufen. Die Einrückung steckt in
     * `depth`, das Zuklappen ebenfalls: alles nach einer Zeile mit größerer
     * Tiefe gehört zu ihr.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $start = $this->startedAt === null ? 0 : TraceNode::micros($this->startedAt);

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach ($this->roots as $root) {
            self::flatten($root, 0, $start, $rows);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function flatten(TraceNode $node, int $depth, int $start, array &$rows): void
    {
        $rows[] = $node->toArray($depth, $start);

        foreach ($node->children as $child) {
            self::flatten($child, $depth + 1, $start, $rows);
        }
    }
}
