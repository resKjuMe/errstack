<?php

namespace App\Support\Performance\Trace;

use App\Models\Event;
use App\Models\Transaction;
use App\Models\TransactionSpan;
use Illuminate\Support\Arr;

/**
 * Ein einzelner Schritt der Spur in voller Länge: das ganze SQL, das HTTP-Ziel,
 * die Zusatzangaben des SDK.
 *
 * Warum das nicht schon in der Liste steht: eine Beschreibung darf 8 KB lang
 * sein ({@see TransactionSpan::DESCRIPTION_LIMIT}), und eine Spur darf
 * zehntausend Schritte haben. Beides zusammen wäre eine Antwort von achtzig
 * Megabyte für eine Seite, auf der jemand am Ende drei Schritte anklickt.
 * Deshalb kommt der Wasserfall mit gekürzten Texten, und **dieser** Gegenstand
 * wird nachgeladen — für genau den Schritt, der gerade offen ist.
 */
final class TraceDetail
{
    /**
     * Der Schritt hinter einer Span-Kennung.
     *
     * Erst unter den Einzelschritten gesucht, dann unter den Transaktionen: die
     * Kennung einer Transaktion ist zugleich die ihres eigenen Balkens, und
     * beide Arten stehen im Wasserfall nebeneinander. Zwei Abfragen im
     * schlechtesten Fall, eine im häufigen.
     *
     * @param  list<int>  $projectIds  Projekte, die der Betrachter sehen darf
     * @return array<string, mixed>|null
     */
    public static function find(string $traceId, string $spanId, array $projectIds): ?array
    {
        if ($projectIds === []) {
            return null;
        }

        $span = TransactionSpan::query()
            ->with(['transaction:id,name,environment,release,project_id', 'transaction.project:id,name,slug'])
            ->where('trace_id', $traceId)
            ->where('span_id', $spanId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('id')
            ->first();

        if ($span !== null) {
            return self::fromSpan($span, $projectIds);
        }

        $transaction = Transaction::query()
            ->with('project:id,name,slug')
            ->where('trace_id', $traceId)
            ->where('span_id', $spanId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('id')
            ->first();

        return $transaction === null ? null : self::fromTransaction($transaction, $projectIds);
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, mixed>
     */
    private static function fromSpan(TransactionSpan $span, array $projectIds): array
    {
        $transaction = $span->transaction;

        return [
            'kind' => TraceNode::KIND_SPAN,
            'spanId' => $span->span_id,
            'parentSpanId' => $span->parent_span_id,
            'op' => $span->op,
            'description' => $span->description,
            'status' => $span->status,
            'startedAt' => $span->started_at->toIso8601String(),
            'finishedAt' => $span->finished_at->toIso8601String(),
            'durationUs' => $span->duration_us,
            'project' => $transaction?->project?->name,
            'transaction' => $transaction?->name,
            'environment' => $transaction?->environment,
            'release' => $transaction?->release,
            'data' => self::entries($span->data),
            'errors' => self::errors($span->trace_id, $span->span_id, $projectIds),
        ];
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<string, mixed>
     */
    private static function fromTransaction(Transaction $transaction, array $projectIds): array
    {
        return [
            'kind' => TraceNode::KIND_TRANSACTION,
            'spanId' => $transaction->span_id,
            'parentSpanId' => $transaction->parent_span_id,
            'op' => $transaction->op,
            'description' => $transaction->name,
            'status' => $transaction->status,
            'startedAt' => $transaction->started_at->toIso8601String(),
            'finishedAt' => $transaction->finished_at->toIso8601String(),
            'durationUs' => $transaction->duration_us,
            'project' => $transaction->project?->name,
            'transaction' => $transaction->name,
            'environment' => $transaction->environment,
            'release' => $transaction->release,
            // Die Messwerte des SDK stehen bei einer Transaktion an der Stelle,
            // an der ein Schritt seine Zusatzangaben hat — beides ist ein freier
            // Feld-Baum, und beides wird gleich dargestellt.
            'data' => self::entries($transaction->measurements),
            'errors' => self::errors($transaction->trace_id, $transaction->span_id, $projectIds),
        ];
    }

    /**
     * Die Fehler dieses Schritts.
     *
     * Sie stehen zwar bereits im Wasserfall an der Zeile; hier kommen sie noch
     * einmal, weil die Einzelheiten auch dann vollständig sein sollen, wenn ein
     * Link direkt auf einen Schritt zeigt und die Liste gar nicht gelesen wurde.
     *
     * @param  list<int>  $projectIds
     * @return list<array<string, mixed>>
     */
    private static function errors(string $traceId, string $spanId, array $projectIds): array
    {
        return Event::query()
            // Dieselbe knappe Auswahl wie im Wasserfall ({@see TraceView}): die
            // Feld-Bäume einer Meldung gehören auf die Fehlerseite, nicht an
            // einen Balken.
            ->select(['id', 'project_id', 'event_group_id', 'event_id', 'trace_id', 'trace_span_id', 'title', 'culprit', 'level', 'occurred_at'])
            ->with('group:id,issue_id')
            ->where('trace_id', $traceId)
            ->where('trace_span_id', $spanId)
            ->whereIn('project_id', $projectIds)
            ->orderBy('occurred_at')
            ->limit(TraceView::ERROR_LIMIT)
            ->get()
            ->map(fn (Event $event): array => TraceError::fromEvent($event)->toArray())
            ->values()
            ->all();
    }

    /**
     * Ein Feld-Baum als Liste aus Name und Wert.
     *
     * Flach gelegt (`db.rows`, nicht `db` mit einem Fach darin), weil die
     * Anzeige eine Tabelle ist und keine zweite Baumansicht. Werte, die selbst
     * Listen sind, werden geschrieben statt aufgeklappt — was ein SDK dort
     * ablegt, ist von Fall zu Fall verschieden, und eine Tabelle mit zwei
     * Spalten kann alles davon zeigen.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function entries(mixed $data): array
    {
        if (! is_array($data) || $data === []) {
            return [];
        }

        /** @var list<array{name: string, value: string}> $entries */
        $entries = [];

        foreach (Arr::dot($data) as $name => $value) {
            $entries[] = [
                'name' => (string) $name,
                'value' => match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    $value === null => 'null',
                    is_scalar($value) => (string) $value,
                    default => (string) json_encode($value),
                },
            ];
        }

        return $entries;
    }
}
