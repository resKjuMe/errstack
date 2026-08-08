<?php

namespace App\Support\Performance\Detection;

use App\Models\Transaction;
use App\Models\TransactionSpan;

/**
 * Ein gespeicherter Ablauf, wie ihn die Erkennung sieht.
 *
 * Einmal geladen, dann acht Mal gelesen — je Erkenner einmal. Die Alternative
 * wäre, jeden Erkenner selbst abfragen zu lassen, und das ist bei acht
 * Erkennern acht Mal dieselbe Abfrage über dieselben paar hundert Zeilen.
 *
 * Der Ausschnitt ist bewusst **eine** Transaktion und nicht der ganze Trace über
 * alle Dienste. Ein N+1 entsteht in einer Anwendung, nicht zwischen zweien; und
 * ein Trace über fünf Dienste zu laden, um in einem davon eine Schleife zu
 * finden, wäre viel Arbeit für dieselbe Antwort. Was dienstübergreifend
 * auffällt, ist der langsame HTTP-Aufruf — und der steht als Schritt in dem
 * Ablauf, der ihn ausgelöst hat.
 */
final class TraceSnapshot
{
    /**
     * Die Vorgänge, die als Datenbank-Abfrage gelten.
     */
    private const DB_OPS = ['db', 'sql'];

    /**
     * Was davon trotzdem keine ist.
     *
     * Manche SDKs melden einen Redis-Zugriff als `db.redis`. Ein
     * Zwischenspeicher-Zugriff in einer Schleife ist aber normal und billig —
     * wer ihn mitzählt, meldet für jede zweite Seite ein N+1. Cache-Zugriffe
     * hat ihren eigenen Erkenner, und der fragt nach etwas anderem: nicht nach
     * der Wiederholung, sondern nach dem Fehlgriff.
     */
    private const NON_DB_OPS = ['db.redis', 'db.cache', 'cache'];

    /**
     * Die Datenbank-Schritte, einmal ausgesiebt.
     *
     * Sie werden von mehreren Erkennern gebraucht, und der N+1-Erkenner fragt
     * sie sogar je Gruppe erneut ab. Ohne diesen Zwischenspeicher wäre das
     * Filtern über mehrere hundert Schritte eine Arbeit, die sich mit jedem
     * Erkenner wiederholt.
     *
     * @var list<SpanRecord>|null
     */
    private ?array $queries = null;

    /**
     * @param  list<SpanRecord>  $spans
     */
    private function __construct(
        public readonly Transaction $transaction,
        public readonly array $spans,
    ) {}

    public static function of(Transaction $transaction): self
    {
        // Der Nullpunkt für alle Zeitrechnung: der Anfang der Transaktion.
        // Damit sind alle Angaben der Schritte kleine Zahlen, und der Vergleich
        // zweier Schritte kommt ohne Datumsobjekte aus.
        $originUs = (int) round($transaction->started_at->getPreciseTimestamp(6));

        $spans = TransactionSpan::query()
            ->where('transaction_id', $transaction->id)
            ->orderBy('position')
            ->get()
            ->map(static fn (TransactionSpan $span): SpanRecord => SpanRecord::fromModel($span, $originUs))
            ->all();

        return new self($transaction, $spans);
    }

    /**
     * Ein Ausschnitt aus fertigen Schritten — der Weg für Tests.
     *
     * @param  list<SpanRecord>  $spans
     */
    public static function fromSpans(Transaction $transaction, array $spans): self
    {
        return new self($transaction, $spans);
    }

    /**
     * Die Schritte eines Vorgangs, in der gemeldeten Reihenfolge.
     *
     * @param  list<string>  $prefixes
     * @return list<SpanRecord>
     */
    public function ofOp(array $prefixes): array
    {
        return array_values(array_filter(
            $this->spans,
            static fn (SpanRecord $span): bool => $span->isOp($prefixes),
        ));
    }

    /**
     * Die Datenbank-Schritte — der Ausgangspunkt der halben Erkennung.
     *
     * @return list<SpanRecord>
     */
    public function queries(): array
    {
        return $this->queries ??= array_values(array_filter(
            $this->ofOp(self::DB_OPS),
            static fn (SpanRecord $span): bool => ! $span->isOp(self::NON_DB_OPS),
        ));
    }

    public function name(): string
    {
        return (string) $this->transaction->name;
    }
}
