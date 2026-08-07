<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\Environment;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\TransactionSpan;
use App\Support\Performance\DurationHistogram;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Performance\TransactionPayload;
use Tests\TestCase;

/**
 * Die Aufnahme von Antwortzeiten, vom angenommenen Envelope-Element bis zur
 * Vorberechnung.
 *
 * Geprüft wird, was die Auswertungen (PF2 bis PF5) voraussetzen: dass eine
 * gemeldete Transaktion vollständig ankommt, dass die Verschachtelung ihrer
 * Einzelschritte erhalten bleibt, dass ein Ablauf über mehrere Dienste über die
 * Trace-Kennung zusammenfindet, dass die Zahlen je Zeitfenster mitgeschrieben
 * werden — und dass eine Transaktion nirgends als Fehler gilt.
 */
class TransactionIngestTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * Nimmt eine Transaktions-Meldung an und lässt sie verarbeiten — denselben
     * Weg, den eine Meldung aus dem Envelope-Endpunkt nimmt.
     *
     * @param  array<string, mixed>  $body
     */
    private function ingest(array $body, ?ProjectKey $key = null): IngestPayload
    {
        $key ??= $this->key();

        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body($body, IngestType::Transaction)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        return $payload->refresh();
    }

    public function test_a_reported_transaction_is_stored_completely(): void
    {
        $payload = $this->ingest(TransactionPayload::make());

        $transaction = Transaction::query()->sole();

        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertSame($payload->project_id, $transaction->project_id);
        $this->assertSame($payload->id, $transaction->ingest_payload_id);
        $this->assertSame($payload->event_id, $transaction->event_id);
        $this->assertSame('GET /projects', $transaction->name);
        $this->assertSame('http.server', $transaction->op);
        $this->assertSame('route', $transaction->source);
        $this->assertSame('ok', $transaction->status);
        $this->assertSame('php', $transaction->platform);
        $this->assertSame('production', $transaction->environment);
        $this->assertSame('errstack@1.2.3', $transaction->release);
        $this->assertSame('4711', $transaction->user_identifier);
        $this->assertSame(1_500_000, $transaction->duration_us);
        $this->assertFalse($transaction->failed());
    }

    public function test_sub_second_timestamps_survive_the_database(): void
    {
        // Ohne Bruchteile in den Spalten begänne jeder Schritt einer Transaktion
        // zur selben Zeit, und die Reihenfolge im Ablauf wäre verloren.
        $this->ingest(TransactionPayload::make([
            'start_timestamp' => '2026-08-07T10:00:00.250+00:00',
            'timestamp' => '2026-08-07T10:00:01.750+00:00',
        ]));

        $transaction = Transaction::query()->sole();

        $this->assertSame('2026-08-07 10:00:00.250', $transaction->started_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('2026-08-07 10:00:01.750', $transaction->finished_at->format('Y-m-d H:i:s.v'));
        $this->assertSame(1_500_000, $transaction->duration_us);
    }

    public function test_the_span_tree_keeps_its_parent_child_relations(): void
    {
        $this->ingest(TransactionPayload::make([], [
            TransactionPayload::span('1111111111111111', 1_770_000_000.5, 0.25),
            TransactionPayload::span('2222222222222222', 1_770_000_000.75, 0.125, [
                'parent_span_id' => '1111111111111111',
                'op' => 'http.client',
                'description' => 'GET https://example.test',
            ]),
        ]));

        $transaction = Transaction::query()->sole();
        $spans = $transaction->spans()->orderBy('position')->get();

        $this->assertSame(2, $transaction->span_count);
        $this->assertCount(2, $spans);

        // Der äußere Schritt hängt an der Transaktion selbst, der innere am
        // äußeren — genau daran ist später zu lesen, wohin die Zeit ging.
        $this->assertSame($transaction->span_id, $spans[0]->parent_span_id);
        $this->assertSame('1111111111111111', $spans[1]->parent_span_id);
        $this->assertSame('http.client', $spans[1]->op);
        $this->assertSame('GET https://example.test', $spans[1]->description);
        $this->assertSame(250_000, $spans[0]->duration_us);
        $this->assertSame(125_000, $spans[1]->duration_us);
        $this->assertSame(['db.system' => 'mysql'], $spans[0]->data);

        // Jeder Schritt trägt Projekt und Trace mit: die Auswertungen filtern
        // danach, ohne die Transaktion dazuzuladen.
        $this->assertSame($transaction->project_id, $spans[0]->project_id);
        $this->assertSame($transaction->trace_id, $spans[0]->trace_id);
    }

    public function test_a_trace_across_several_services_is_found_by_its_trace_id(): void
    {
        $traceId = 'aaaabbbbccccddddeeeeffff00001111';

        // Zwei Dienste, zwei Projekte, ein Ablauf: das Frontend ruft das Backend,
        // und dessen Transaktion nennt die des Frontends als Elternteil.
        $frontend = $this->key();
        $backend = $this->key();

        $this->ingest(TransactionPayload::make([
            'transaction' => 'GET /checkout',
            'contexts' => ['trace' => [
                'trace_id' => $traceId,
                'span_id' => '1000000000000000',
                'op' => 'pageload',
            ]],
        ]), $frontend);

        $this->ingest(TransactionPayload::make([
            'transaction' => 'POST /api/orders',
            'contexts' => ['trace' => [
                'trace_id' => $traceId,
                'span_id' => '2000000000000000',
                'parent_span_id' => '1000000000000000',
                'op' => 'http.server',
            ]],
        ]), $backend);

        $trace = Transaction::query()->where('trace_id', $traceId)->orderBy('id')->get();

        $this->assertCount(2, $trace);
        $this->assertNotSame($trace[0]->project_id, $trace[1]->project_id);
        $this->assertNull($trace[0]->parent_span_id);
        $this->assertSame($trace[0]->span_id, $trace[1]->parent_span_id);
    }

    public function test_the_time_window_is_written_along(): void
    {
        $this->ingest(TransactionPayload::make([
            'start_timestamp' => '2026-08-07T10:00:31.500+00:00',
            'timestamp' => '2026-08-07T10:00:33.000+00:00',
        ]));

        $aggregate = TransactionAggregate::query()->sole();

        // Abgeschnitten, nicht gerundet: eine Messung um 10:00:31 gehört in das
        // Fenster 10:00 und nicht in das noch nicht begonnene 10:01.
        $this->assertSame('2026-08-07 10:00:00', $aggregate->window_start->format('Y-m-d H:i:s'));
        $this->assertSame('GET /projects', $aggregate->name);
        $this->assertSame('http.server', $aggregate->op);
        $this->assertSame('production', $aggregate->environment);
        $this->assertSame(1, $aggregate->transaction_count);
        $this->assertSame(0, $aggregate->failure_count);
        $this->assertSame(1_500_000, $aggregate->duration_sum_us);
        $this->assertSame(1_500_000, $aggregate->duration_min_us);
        $this->assertSame(1_500_000, $aggregate->duration_max_us);
        $this->assertSame(
            [DurationHistogram::bucketFor(1_500_000) => 1],
            $aggregate->histogram()->toArray(),
        );
    }

    public function test_transactions_of_the_same_minute_share_one_window(): void
    {
        $key = $this->key();

        $this->ingest(TransactionPayload::make([
            'start_timestamp' => '2026-08-07T10:00:05.000+00:00',
            'timestamp' => '2026-08-07T10:00:05.100+00:00',
        ]), $key);

        $this->ingest(TransactionPayload::make([
            'start_timestamp' => '2026-08-07T10:00:45.000+00:00',
            'timestamp' => '2026-08-07T10:00:48.000+00:00',
            // Dieselbe Operation wie oben: sie steht im Schlüssel des
            // Zeitfensters, und mit einer anderen wäre es ein anderes Fenster.
            'contexts' => ['trace' => ['op' => 'http.server', 'status' => 'internal_error']],
        ]), $key);

        // Eine andere Minute ist ein anderes Fenster.
        $this->ingest(TransactionPayload::make([
            'start_timestamp' => '2026-08-07T10:01:05.000+00:00',
            'timestamp' => '2026-08-07T10:01:05.500+00:00',
        ]), $key);

        $windows = TransactionAggregate::query()->orderBy('window_start')->get();

        $this->assertCount(2, $windows);
        $this->assertSame(2, $windows[0]->transaction_count);
        $this->assertSame(1, $windows[0]->failure_count);
        $this->assertSame(3_100_000, $windows[0]->duration_sum_us);
        $this->assertSame(100_000, $windows[0]->duration_min_us);
        $this->assertSame(3_000_000, $windows[0]->duration_max_us);
        $this->assertSame(1_550_000.0, $windows[0]->averageUs());
        $this->assertSame(0.5, $windows[0]->failureRate());
        $this->assertSame(2, $windows[0]->histogram()->count());
        $this->assertSame(1, $windows[1]->transaction_count);
    }

    public function test_a_hundred_spans_are_stored_in_one_go(): void
    {
        $spans = [];

        for ($i = 0; $i < 100; $i++) {
            $spans[] = TransactionPayload::span(
                str_pad((string) $i, 16, '0', STR_PAD_LEFT),
                1_770_000_000.5 + $i / 1000,
                0.001,
            );
        }

        $key = $this->key();
        $spanWrites = 0;

        DB::listen(function (QueryExecuted $query) use (&$spanWrites): void {
            if (str_contains($query->sql, 'transaction_spans')) {
                $spanWrites++;
            }
        });

        $this->ingest(TransactionPayload::make([], $spans), $key);

        // Der Punkt der Zusage: die Schritte kosten **eine** Einfügung, nicht
        // hundert. Daran hängt, dass die Verarbeitungsdauer nicht mit jeder
        // Anwendung wächst, die ihre Aufrufe genauer aufschlüsselt — ein Umlauf
        // je Schritt wäre bei hundert Schritten die ganze Zeitüberschreitung.
        // Abgelesen vor den Prüfzeilen, weil auch die auf die Tabelle zugreifen.
        $this->assertSame(1, $spanWrites);

        $transaction = Transaction::query()->sole();

        $this->assertSame(100, $transaction->span_count);
        $this->assertSame(100, $transaction->spans()->count());
    }

    public function test_spans_beyond_the_limit_are_counted_as_discarded(): void
    {
        config()->set('ingest.performance.max_spans', 3);

        $spans = [];

        for ($i = 0; $i < 5; $i++) {
            $spans[] = TransactionPayload::span(str_pad((string) $i, 16, '0', STR_PAD_LEFT), 1_770_000_000.5, 0.001);
        }

        $this->ingest(TransactionPayload::make([], $spans));

        $this->assertSame(3, TransactionSpan::query()->count());

        $discard = IngestDiscard::query()
            ->where('reason', DiscardReason::TooManyItems->value)
            ->sole();

        $this->assertSame(2, $discard->quantity);
        $this->assertSame(IngestType::Transaction->value, $discard->category);
    }

    public function test_an_unmeasurable_transaction_is_counted_and_stored_nowhere(): void
    {
        $body = TransactionPayload::make();
        unset($body['timestamp']);

        $payload = $this->ingest($body);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, TransactionAggregate::query()->count());

        // Die Meldung selbst gilt als ausgewertet: der Rumpf war lesbar, es war
        // nur nichts darin zu messen. Ein Wiederholen käme zum selben Schluss.
        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertSame(1, IngestDiscard::query()->where('reason', DiscardReason::Unreadable->value)->count());
    }

    public function test_an_error_report_produces_no_transaction(): void
    {
        // Die Zusage in beide Richtungen: eine Fehlermeldung wird nicht zur
        // Messung, und der Schritt fasst sie überhaupt nicht an.
        $payload = IngestPayload::factory()->viaKey($this->key())->create();

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(ProcessingState::Processed, $payload->refresh()->processing_state);
    }

    public function test_processing_the_same_payload_twice_does_not_double_the_numbers(): void
    {
        $payload = $this->ingest(TransactionPayload::make([], [
            TransactionPayload::span('1111111111111111', 1_770_000_000.5, 0.25),
        ]));

        // Wie nach einem Fehlschlag oder einer Änderung an der Verarbeitung: die
        // Rohdaten laufen erneut durch.
        $payload->forceFill([
            'processing_state' => ProcessingState::Pending,
            'processed_at' => null,
        ])->save();

        ProcessIngestPayload::dispatch($payload->fresh());

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, TransactionSpan::query()->count());

        $aggregate = TransactionAggregate::query()->sole();

        $this->assertSame(1, $aggregate->transaction_count);
        $this->assertSame(1, $aggregate->histogram()->count());
    }

    public function test_the_environment_of_a_transaction_appears_in_the_filter_bar(): void
    {
        $payload = $this->ingest(TransactionPayload::make(['environment' => 'staging']));

        $environment = Environment::query()
            ->where('project_id', $payload->project_id)
            ->where('name', 'staging')
            ->sole();

        $this->assertSame('staging', Transaction::query()->sole()->environment);
        $this->assertNotNull($environment->first_seen_at);
    }

    public function test_a_transaction_without_environment_lands_in_the_default_one(): void
    {
        // Die Vorberechnung führt die Umgebung im eindeutigen Schlüssel — ohne
        // festen Ersatzwert entstünde für jede Messung ohne Angabe eine eigene
        // Zeile, und zusammengefasst würde nichts.
        $key = $this->key();

        foreach ([TransactionPayload::make(), TransactionPayload::make()] as $body) {
            unset($body['environment']);

            $this->ingest($body, $key);
        }

        $aggregate = TransactionAggregate::query()->sole();

        $this->assertNotSame('', $aggregate->environment);
        $this->assertSame(2, $aggregate->transaction_count);
    }
}
