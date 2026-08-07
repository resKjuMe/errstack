<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\ProcessedEvent;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Support\Ingest\Processing\ProcessingMetrics;
use App\Support\Ingest\Processing\ProcessingPipeline;
use App\Support\Ingest\Processing\Steps\DecodePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\Ingest\DroppingStep;
use Tests\Support\Ingest\FailingStep;
use Tests\Support\Ingest\RecordingStep;
use Tests\TestCase;

/**
 * Die Verarbeitung im Hintergrund.
 *
 * Geprüft wird der Rahmen, nicht was die Schritte inhaltlich tun: dass jede
 * Meldung genau einmal durchläuft, dass ein Fehler zu einer Wiederholung und
 * nicht zu einem Verlust führt, dass Gescheitertes auffindbar bleibt und dass
 * sich die Kette erweitern lässt, ohne einen bestehenden Schritt anzufassen.
 * Das sind die Zusagen, auf die sich alles Weitere (I4 bis I9) verlässt.
 */
class IngestProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingStep::reset();
        FailingStep::failTimes(0);
    }

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    private function payload(ProjectKey $key, ?string $eventId = null, IngestType $type = IngestType::Event): IngestPayload
    {
        return IngestPayload::factory()
            ->viaKey($key)
            ->create(array_filter([
                'event_id' => $eventId,
                'type' => $type,
            ]));
    }

    /**
     * Führt den Job aus, wie die Warteschlange es täte — mit Job-Objekt,
     * damit die Zahl der Versuche stimmt.
     */
    private function process(IngestPayload $payload): void
    {
        ProcessIngestPayload::dispatch($payload);
    }

    /**
     * @param  list<class-string>  $steps
     */
    private function useSteps(array $steps): void
    {
        config(['ingest.processing.steps' => $steps]);
    }

    public function test_an_accepted_report_is_processed_and_its_duration_recorded(): void
    {
        $payload = $this->payload($this->key());

        $this->process($payload);

        $payload->refresh();

        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertNotNull($payload->processed_at);
        $this->assertNotNull($payload->duration_ms);
        $this->assertSame(1, $payload->attempts);
    }

    /**
     * Der eigentliche Punkt der Aufgabe: ein SDK, dessen Zustellung
     * unbestätigt blieb, schickt dieselbe Meldung noch einmal. Angenommen wird
     * sie zweimal — ausgewertet genau einmal.
     */
    public function test_a_second_delivery_of_the_same_event_is_processed_only_once(): void
    {
        $key = $this->key();
        $eventId = IngestPayload::freshEventId();

        $first = $this->payload($key, $eventId);
        $second = $this->payload($key, $eventId);

        $this->process($first);
        $this->process($second);

        $this->assertSame(ProcessingState::Processed, $first->refresh()->processing_state);
        $this->assertSame(ProcessingState::Duplicate, $second->refresh()->processing_state);

        $this->assertSame(1, ProcessedEvent::query()->count());

        // Was doppelt war, bleibt sichtbar — sonst wäre nicht zu erklären,
        // warum zwei angenommene Meldungen zu einem Ergebnis geführt haben.
        $discard = IngestDiscard::query()->sole();
        $this->assertSame(DiscardReason::Duplicate->value, $discard->reason);
        $this->assertSame(DiscardOrigin::Server, $discard->origin);
        $this->assertSame(1, $discard->quantity);
    }

    /**
     * Dieselbe Nummer in zwei Projekten sind zwei Ereignisse. SDK-Nummern sind
     * zwar Zufallswerte, aber die Trennung der Projekte darf nicht davon
     * abhängen, wie gut dieser Zufall ist.
     */
    public function test_the_same_event_id_in_another_project_is_not_a_duplicate(): void
    {
        $eventId = IngestPayload::freshEventId();

        $here = $this->payload($this->key(), $eventId);
        $there = $this->payload($this->key(), $eventId);

        $this->process($here);
        $this->process($there);

        $this->assertSame(ProcessingState::Processed, $here->refresh()->processing_state);
        $this->assertSame(ProcessingState::Processed, $there->refresh()->processing_state);
    }

    /**
     * Ein Anhang trägt die Nummer der Meldung, zu der er gehört. Würde er an
     * ihr gemessen, verschwände jeder Screenshot, der zusammen mit seinem
     * Fehler ankommt — und das ist der Normalfall.
     */
    public function test_an_attachment_sharing_the_event_id_is_not_a_duplicate(): void
    {
        $key = $this->key();
        $eventId = IngestPayload::freshEventId();

        $event = $this->payload($key, $eventId);
        $attachment = $this->payload($key, $eventId, IngestType::Attachment);

        $this->process($event);
        $this->process($attachment);

        $this->assertSame(ProcessingState::Processed, $event->refresh()->processing_state);
        $this->assertSame(ProcessingState::Processed, $attachment->refresh()->processing_state);
    }

    /**
     * Ein Schritt, der scheitert, darf die Meldung nicht verbrauchen: sie
     * bleibt im Rückstand stehen, damit der nächste Versuch sie findet.
     */
    public function test_a_failing_step_leaves_the_payload_for_another_attempt(): void
    {
        $this->useSteps([FailingStep::class]);
        FailingStep::failTimes(1);

        $payload = $this->payload($this->key());

        try {
            (new ProcessIngestPayload($payload))->handle(app(ProcessingPipeline::class));
            $this->fail('Der Fehler des Schritts hätte durchschlagen müssen.');
        } catch (RuntimeException) {
            // So kommt die Wiederholung zustande — die Ausnahme ist gewollt.
        }

        $this->assertSame(ProcessingState::Pending, $payload->refresh()->processing_state);
    }

    /**
     * Der Anspruch auf die Ereignis-Nummer wird beim ersten Versuch gestellt.
     * Erkennte der zweite Versuch ihn nicht als seinen eigenen, hielte sich
     * jede Wiederholung für ein Doppel — und die Meldung käme nie durch.
     */
    public function test_a_later_attempt_of_the_same_payload_is_not_a_duplicate_of_itself(): void
    {
        $this->useSteps([DecodePayload::class, FailingStep::class]);
        FailingStep::failTimes(1);

        $payload = $this->payload($this->key());

        try {
            (new ProcessIngestPayload($payload))->handle(app(ProcessingPipeline::class));
        } catch (RuntimeException) {
            // Erster Anlauf, wie im Betrieb gescheitert.
        }

        (new ProcessIngestPayload($payload))->handle(app(ProcessingPipeline::class));

        $this->assertSame(ProcessingState::Processed, $payload->refresh()->processing_state);
    }

    /**
     * Nach dem letzten vergeblichen Versuch: die Meldung ist als gescheitert
     * auffindbar, und ihre Nummer ist wieder frei — sonst würde eine erneute
     * Zustellung als Doppel abgetan, obwohl nie etwas ausgewertet wurde.
     */
    public function test_a_final_failure_is_recorded_and_frees_the_event_id(): void
    {
        $this->useSteps([FailingStep::class]);
        FailingStep::failTimes(10);

        $payload = $this->payload($this->key());

        try {
            $this->process($payload);
        } catch (RuntimeException) {
            // Die Warteschlange meldet den Fehlschlag und wirft weiter.
        }

        $payload->refresh();

        $this->assertSame(ProcessingState::Failed, $payload->processing_state);
        $this->assertNotNull($payload->failure);
        $this->assertSame(0, ProcessedEvent::query()->count());

        $this->assertTrue(
            IngestPayload::query()->failedProcessing()->whereKey($payload->id)->exists(),
            'Gescheiterte Meldungen müssen auffindbar sein.',
        );
    }

    /**
     * Ein zweites Mal ausgelieferter Job trifft auf eine Meldung, die schon ein
     * Ergebnis hat. Er lässt es stehen, statt es zu überschreiben.
     */
    public function test_a_second_run_does_not_touch_a_finished_payload(): void
    {
        $payload = $this->payload($this->key());

        $this->process($payload);
        $processedAt = $payload->refresh()->processed_at;

        $this->travel(5)->seconds();
        $this->process($payload);

        $this->assertEquals($processedAt, $payload->refresh()->processed_at);
    }

    /**
     * Gescheitert sein kann auch ein Lauf, dessen Meldung längst ausgewertet
     * war — eine zweite Auslieferung, die in eine Zeitüberschreitung läuft.
     * Deren Ergebnis darf der Fehlschlag nicht nachträglich einkassieren.
     */
    public function test_a_failure_does_not_overwrite_an_already_finished_report(): void
    {
        $payload = $this->payload($this->key());

        $this->process($payload);
        $this->assertSame(ProcessingState::Processed, $payload->refresh()->processing_state);

        (new ProcessIngestPayload($payload))->failed(new RuntimeException('Zeitüberschreitung.'));

        $payload->refresh();
        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertNull($payload->failure);
    }

    /**
     * Die Zusage an I4 bis I9: ein Schritt kommt hinzu, indem er in die Liste
     * geschrieben wird — kein bestehender Schritt und kein Rahmen wird dafür
     * angefasst.
     */
    public function test_the_chain_runs_configured_steps_in_order(): void
    {
        $this->useSteps([DecodePayload::class, RecordingStep::class]);

        $payload = $this->payload($this->key());

        $this->process($payload);

        $this->assertSame([$payload->id], RecordingStep::$seen);
        $this->assertSame(ProcessingState::Processed, $payload->refresh()->processing_state);
    }

    /**
     * Sortiert ein Schritt aus, endet die Kette dort — auch wenn er brav
     * weitergereicht hat. Sonst arbeiteten die folgenden Schritte auf einer
     * Meldung weiter, die es nicht mehr geben soll.
     */
    public function test_a_dropped_report_stops_the_chain_and_is_counted(): void
    {
        $this->useSteps([DroppingStep::class, RecordingStep::class]);

        $payload = $this->payload($this->key());

        $this->process($payload);

        $this->assertSame([], RecordingStep::$seen);

        $payload->refresh();
        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
        $this->assertSame(DiscardReason::Unreadable->value, $payload->failure);

        $discard = IngestDiscard::query()->sole();
        $this->assertSame(DiscardReason::Unreadable->value, $discard->reason);
        $this->assertSame('testfilter', $discard->category);
    }

    /**
     * Nutzdaten, die kein JSON mehr sind, lassen sich nicht auswerten — und
     * eine Wiederholung änderte daran nichts. Also aussortieren statt es
     * fünfmal zu versuchen.
     */
    public function test_an_unreadable_body_is_dropped_instead_of_retried(): void
    {
        $payload = $this->payload($this->key());
        $payload->forceFill(['payload' => 'kein json'])->save();

        $this->process($payload);

        $this->assertSame(ProcessingState::Dropped, $payload->refresh()->processing_state);
    }

    /**
     * Ein Anhang ist kein JSON — das ist kein Mangel, sondern seine Natur.
     */
    public function test_binary_items_pass_the_decoding_step(): void
    {
        $key = $this->key();

        $attachment = IngestPayload::accept(
            key: $key,
            eventId: IngestPayload::freshEventId(),
            payload: "\x89PNG\r\n\x1a\n\x00",
            type: IngestType::Attachment,
        );

        $this->process($attachment);

        $this->assertSame(ProcessingState::Processed, $attachment->refresh()->processing_state);
    }

    /**
     * Rückstand und Dauern sind die Zahlen, an denen im Betrieb hängt, ob mehr
     * Arbeiter nötig sind oder ein Schritt langsam geworden ist.
     */
    public function test_backlog_and_durations_are_measurable(): void
    {
        $key = $this->key();
        $metrics = app(ProcessingMetrics::class);

        $processed = $this->payload($key);
        $this->payload($key);
        $this->payload($key);

        $this->assertSame(3, $metrics->backlog());
        $this->assertNotNull($metrics->oldestPendingSeconds());

        $this->process($processed);

        $this->assertSame(2, $metrics->backlog());
        $this->assertSame(1, $metrics->durations()['count']);
        $this->assertSame(2, $metrics->states()[ProcessingState::Pending->value]);
        $this->assertSame(1, $metrics->states()[ProcessingState::Processed->value]);
    }

    /**
     * Der Endpunkt legt ab und reiht ein — ausgewertet wird außerhalb der
     * Anfrage, auf die die überwachte Anwendung wartet.
     */
    public function test_the_store_endpoint_queues_the_processing(): void
    {
        Queue::fake();

        $key = $this->key();

        $this->call(
            'POST',
            "/api/{$key->project_id}/store/",
            server: [
                'HTTP_X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key->public_key}",
                'CONTENT_TYPE' => 'application/json',
            ],
            content: (string) json_encode(['message' => 'Etwas ist kaputt.']),
        )->assertStatus(200);

        Queue::assertPushedOn('ingest', ProcessIngestPayload::class);
    }
}
