<?php

namespace Tests\Unit;

use App\Support\Performance\TransactionEvent;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Performance\TransactionPayload;

/**
 * Das Lesen einer gemeldeten Transaktion.
 *
 * Alles hier prüft denselben Punkt: die Angaben kommen von einem fremden SDK,
 * und keine davon ist zugesichert. Eine fehlende Angabe darf höchstens ein
 * leeres Feld ergeben — eine verlorene Messung nur dann, wenn schlicht nichts zu
 * messen ist.
 */
class TransactionEventTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function read(array $body, int $maxSpans = 1000): ?TransactionEvent
    {
        return TransactionEvent::fromPayload($body, (string) $body['event_id'], $maxSpans);
    }

    public function test_it_reads_name_operation_trace_and_duration(): void
    {
        $event = $this->read(TransactionPayload::make());

        $this->assertNotNull($event);
        $this->assertSame('GET /projects', $event->name);
        $this->assertSame('http.server', $event->op);
        $this->assertSame('route', $event->source);
        $this->assertSame('ok', $event->status);
        $this->assertSame('production', $event->environment);
        $this->assertSame('errstack@1.2.3', $event->release);
        $this->assertSame('4711', $event->userIdentifier);
        $this->assertSame('1234567890abcdef1234567890abcdef', $event->traceId);
        $this->assertSame('abcdef1234567890', $event->spanId);
        $this->assertNull($event->parentSpanId);
        $this->assertSame(1_500_000, $event->durationUs);
        $this->assertFalse($event->failed());
    }

    public function test_iso_timestamps_are_read_like_numeric_ones(): void
    {
        // Beide Formen kommen im Feld vor — die Zahl bei den Server-SDKs, der
        // Text bei den älteren.
        $event = $this->read(TransactionPayload::make([
            'start_timestamp' => '2026-08-07T10:00:00.000+00:00',
            'timestamp' => '2026-08-07T10:00:02.250+00:00',
        ]));

        $this->assertNotNull($event);
        $this->assertSame(2_250_000, $event->durationUs);
        $this->assertSame('2026-08-07 10:00:00.000', $event->startedAt->format('Y-m-d H:i:s.v'));
    }

    public function test_a_transaction_without_timestamps_cannot_be_measured(): void
    {
        $body = TransactionPayload::make();
        unset($body['timestamp']);

        $this->assertNull($this->read($body));
    }

    public function test_a_clock_running_backwards_yields_no_duration_instead_of_a_huge_one(): void
    {
        $event = $this->read(TransactionPayload::make([
            'start_timestamp' => 1_770_000_010.0,
            'timestamp' => 1_770_000_000.0,
        ]));

        $this->assertNotNull($event);
        $this->assertSame(0, $event->durationUs);
    }

    public function test_a_timestamp_in_milliseconds_is_rejected_instead_of_stored_as_year_58026(): void
    {
        // Der häufigste Uhrenfehler überhaupt: ein SDK schickt Millisekunden
        // statt Sekunden. Ungeprüft bräche daran die Einfügung ab, und aus einer
        // erklärbaren Verwerfung würde ein Fehlschlag mit Wiederholungen.
        $this->assertNull($this->read(TransactionPayload::make([
            'start_timestamp' => 1_770_000_000_000,
            'timestamp' => 1_770_000_001_500,
        ])));
    }

    public function test_a_clock_far_in_the_future_is_rejected(): void
    {
        $this->assertNull($this->read(TransactionPayload::make([
            'start_timestamp' => '2099-01-01T00:00:00+00:00',
            'timestamp' => '2099-01-01T00:00:01+00:00',
        ])));
    }

    public function test_a_clock_slightly_ahead_keeps_its_measurement(): void
    {
        // Uhren laufen auseinander; wer um Minuten vorgeht, soll seine Messungen
        // nicht verlieren.
        $startedAt = CarbonImmutable::now()->addMinutes(5);

        $event = $this->read(TransactionPayload::make([
            'start_timestamp' => $startedAt->toIso8601String(),
            'timestamp' => $startedAt->addSecond()->toIso8601String(),
        ]));

        $this->assertNotNull($event);
        $this->assertSame(1_000_000, $event->durationUs);
    }

    public function test_a_nameless_transaction_keeps_its_measurement(): void
    {
        $body = TransactionPayload::make();
        unset($body['transaction']);

        $event = $this->read($body);

        $this->assertNotNull($event);
        $this->assertSame(TransactionEvent::UNNAMED, $event->name);
    }

    public function test_a_missing_trace_context_is_replaced_by_a_reproducible_one(): void
    {
        // Wichtig ist die Reproduzierbarkeit: derselbe Rumpf muss beim zweiten
        // Durchlauf dieselbe Kennung ergeben, sonst entsteht bei jedem
        // Wiederholungsversuch ein neuer Trace.
        $body = TransactionPayload::make();
        unset($body['contexts']);

        $first = $this->read($body);
        $second = $this->read($body);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($body['event_id'], $first->traceId);
        $this->assertSame($first->traceId, $second->traceId);
        $this->assertSame($first->spanId, $second->spanId);
    }

    public function test_trace_identifiers_are_normalised_like_event_numbers(): void
    {
        // Dasselbe Trace in zwei Schreibweisen wäre für die Zuordnung zweierlei —
        // und ein Ablauf über mehrere Dienste zerfiele in zwei Hälften.
        $event = $this->read(TransactionPayload::make([
            'contexts' => ['trace' => [
                'trace_id' => '12345678-90AB-CDEF-1234-567890ABCDEF',
                'span_id' => 'ABCDEF1234567890',
                'parent_span_id' => '1111222233334444',
            ]],
        ]));

        $this->assertNotNull($event);
        $this->assertSame('1234567890abcdef1234567890abcdef', $event->traceId);
        $this->assertSame('abcdef1234567890', $event->spanId);
        $this->assertSame('1111222233334444', $event->parentSpanId);
    }

    public function test_a_failed_status_counts_as_a_failure_but_a_cancelled_one_does_not(): void
    {
        $failed = $this->read(TransactionPayload::make([
            'contexts' => ['trace' => ['status' => 'internal_error']],
        ]));

        $cancelled = $this->read(TransactionPayload::make([
            'contexts' => ['trace' => ['status' => 'cancelled']],
        ]));

        $unknownToUs = $this->read(TransactionPayload::make([
            'contexts' => ['trace' => ['status' => 'brandneuer_status']],
        ]));

        $this->assertNotNull($failed);
        $this->assertNotNull($cancelled);
        $this->assertNotNull($unknownToUs);
        $this->assertTrue($failed->failed());
        $this->assertFalse($cancelled->failed());

        // Ein Status, den wir nicht kennen, darf die Fehlerrate nicht auf 100 %
        // springen lassen.
        $this->assertFalse($unknownToUs->failed());
    }

    public function test_spans_keep_their_order_parents_and_data(): void
    {
        $event = $this->read(TransactionPayload::make([], [
            TransactionPayload::span('1111111111111111', 1_770_000_000.2, 0.05),
            TransactionPayload::span('2222222222222222', 1_770_000_000.3, 0.10, [
                'parent_span_id' => '1111111111111111',
                'op' => 'http.client',
                'description' => 'GET https://example.test',
            ]),
        ]));

        $this->assertNotNull($event);
        $this->assertCount(2, $event->spans);
        $this->assertSame('1111111111111111', $event->spans[0]->spanId);
        $this->assertSame(50_000, $event->spans[0]->durationUs);
        $this->assertSame(['db.system' => 'mysql'], $event->spans[0]->data);
        $this->assertSame('1111111111111111', $event->spans[1]->parentSpanId);
        $this->assertSame('http.client', $event->spans[1]->op);
        $this->assertSame(100_000, $event->spans[1]->durationUs);
    }

    public function test_an_unreadable_span_is_counted_and_the_rest_is_kept(): void
    {
        $event = $this->read(TransactionPayload::make([], [
            TransactionPayload::span('1111111111111111', 1_770_000_000.2, 0.05),
            ['op' => 'db.sql.query'],
            'kein Objekt',
        ]));

        $this->assertNotNull($event);
        $this->assertCount(1, $event->spans);
        $this->assertSame(2, $event->unreadableSpans);
        $this->assertSame(0, $event->excessSpans);
    }

    public function test_the_same_span_reported_twice_is_stored_once(): void
    {
        // Sonst scheitert die Einfügung aller Schritte an der Doppelung — und
        // eine Transaktion stünde ohne ihre Erklärung da.
        $event = $this->read(TransactionPayload::make([], [
            TransactionPayload::span('1111111111111111', 1_770_000_000.5, 0.25, ['op' => 'zuerst']),
            TransactionPayload::span('1111111111111111', 1_770_000_000.75, 0.25, ['op' => 'danach']),
        ]));

        $this->assertNotNull($event);
        $this->assertCount(1, $event->spans);

        // Der erste gewinnt: die zweite Meldung desselben Schritts ist eine
        // Wiederholung, keine zusätzliche Messung.
        $this->assertSame('zuerst', $event->spans[0]->op);
    }

    public function test_spans_beyond_the_limit_are_counted_not_silently_dropped(): void
    {
        $spans = [];

        for ($i = 0; $i < 10; $i++) {
            $spans[] = TransactionPayload::span(str_pad((string) $i, 16, '0', STR_PAD_LEFT), 1_770_000_000.2, 0.01);
        }

        $event = $this->read(TransactionPayload::make([], $spans), maxSpans: 4);

        $this->assertNotNull($event);
        $this->assertCount(4, $event->spans);
        $this->assertSame(6, $event->excessSpans);
    }

    public function test_measurements_keep_value_and_unit_and_drop_everything_else(): void
    {
        $event = $this->read(TransactionPayload::make([
            'measurements' => [
                'lcp' => ['value' => 1234.5, 'unit' => 'millisecond'],
                'frames' => ['value' => 60],
                'bewertung' => ['value' => 'gut'],
                'kaputt' => 'kein Objekt',
            ],
        ]));

        $this->assertNotNull($event);
        $this->assertSame([
            'lcp' => ['value' => 1234.5, 'unit' => 'millisecond'],
            'frames' => ['value' => 60.0, 'unit' => null],
        ], $event->measurements);
    }

    public function test_a_long_name_is_shortened_instead_of_costing_the_measurement(): void
    {
        $event = $this->read(TransactionPayload::make([
            'transaction' => str_repeat('a', 500),
        ]));

        $this->assertNotNull($event);
        $this->assertSame(200, strlen($event->name));
    }

    public function test_the_user_identifier_falls_back_from_id_to_address(): void
    {
        $event = $this->read(TransactionPayload::make([
            'user' => ['ip_address' => '203.0.113.7'],
        ]));

        $this->assertNotNull($event);
        $this->assertSame('203.0.113.7', $event->userIdentifier);
    }
}
