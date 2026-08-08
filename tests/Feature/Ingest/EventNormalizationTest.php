<?php

namespace Tests\Feature\Ingest;

use App\Enums\EventLevel;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Support\Ingest\Normalization\NormalizedEvent;
use App\Support\Ingest\Processing\Steps\DecodePayload;
use App\Support\Ingest\Processing\Steps\NormalizeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ingest\CapturingStep;
use Tests\TestCase;

/**
 * Die Normalisierung als Teil der Kette: eine Meldung kommt über den Endpunkt
 * herein und liegt danach als ausgewerteter Datensatz vor.
 *
 * Der Unterschied zum Test der Normalisierung für sich ({@see
 * \Tests\Unit\EventNormalizerTest}) ist die Zusage, um die es hier geht: dass
 * der Schritt in der Kette steht, dass das Original unberührt liegen bleibt und
 * dass ein zweiter Durchlauf keinen zweiten Datensatz erzeugt.
 */
class EventNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function accept(ProjectKey $key, array $body, IngestType $type = IngestType::Event): IngestPayload
    {
        $body['event_id'] ??= IngestPayload::freshEventId();

        return IngestPayload::accept(
            $key,
            $body['event_id'],
            (string) json_encode($body),
            $type,
        );
    }

    public function test_an_accepted_report_becomes_a_normalized_event(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, [
            'platform' => 'php',
            'level' => 'error',
            'environment' => 'production',
            'release' => 'berichte@2.1.0',
            'server_name' => 'web-03',
            'sdk' => ['name' => 'sentry.php', 'version' => '4.0.0'],
            'exception' => ['values' => [
                ['type' => 'PDOException', 'value' => 'connection refused'],
                [
                    'type' => 'RuntimeException',
                    'value' => 'Bericht konnte nicht erzeugt werden',
                    'stacktrace' => ['frames' => [
                        ['filename' => 'vendor/framework/Kernel.php', 'function' => 'handle', 'in_app' => false],
                        ['filename' => 'app/Report.php', 'function' => 'build', 'lineno' => 88, 'in_app' => true],
                    ]],
                ],
            ]],
            'request' => ['url' => 'https://beispiel.test/berichte', 'method' => 'GET'],
            'user' => ['id' => '4711'],
            'breadcrumbs' => ['values' => [['message' => 'Bericht angefordert', 'category' => 'ui.click']]],
            'tags' => ['mandant' => 'acme'],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->firstOrFail();

        $this->assertSame($payload->id, $event->ingest_payload_id);
        $this->assertSame($payload->project_id, $event->project_id);
        $this->assertSame($payload->event_id, $event->event_id);
        $this->assertSame(EventLevel::Error, $event->level);
        $this->assertSame('php', $event->platform);
        $this->assertSame('production', $event->environment);
        $this->assertSame('berichte@2.1.0', $event->release);
        $this->assertSame('web-03', $event->server_name);
        $this->assertSame('RuntimeException: Bericht konnte nicht erzeugt werden', $event->title);
        $this->assertSame('build (app/Report.php)', $event->culprit);
        $this->assertSame('sentry.php/4.0.0', $event->sdkIdentifier());

        // Die Ursachenkette in ihrer Reihenfolge, der Stacktrace vollständig.
        $this->assertCount(2, $event->exceptions ?? []);
        $this->assertSame('PDOException', data_get($event->exceptions, '0.type'));
        $this->assertCount(2, $event->frames());

        $this->assertSame('https://beispiel.test/berichte', data_get($event->request, 'url'));
        $this->assertSame('4711', data_get($event->user, 'id'));
        $this->assertSame('Bericht angefordert', data_get($event->breadcrumbs, '0.message'));
        $this->assertSame(['mandant' => 'acme'], $event->tags);
        $this->assertFalse($event->wasReduced());

        $payload->refresh();
        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
    }

    public function test_the_original_report_is_kept_unchanged(): void
    {
        $key = $this->key();

        $body = [
            'event_id' => IngestPayload::freshEventId(),
            'platform' => 'javascript',
            'message' => 'kaputt',
        ];

        $raw = (string) json_encode($body);
        $payload = IngestPayload::accept($key, $body['event_id'], $raw);

        ProcessIngestPayload::dispatch($payload);

        // Die Zusage, auf der die ganze Kette beruht: wird an einem Schritt
        // etwas geändert, lässt sich alles erneut auswerten. Dafür muss das
        // Original Zeichen für Zeichen so liegen, wie es kam.
        $this->assertSame($raw, $payload->fresh()?->bytes());
    }

    public function test_a_report_with_only_a_message_is_stored_without_a_stacktrace(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, ['message' => 'Nur eine Nachricht']);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->firstOrFail();

        $this->assertFalse($event->hasException());
        $this->assertSame('Nur eine Nachricht', $event->title);
        $this->assertSame([], $event->frames());
        $this->assertSame(ProcessingState::Processed, $payload->fresh()?->processing_state);
    }

    public function test_a_javascript_report_keeps_its_full_stacktrace(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, [
            'platform' => 'javascript',
            'exception' => ['values' => [[
                'type' => 'TypeError',
                'value' => "Cannot read properties of undefined (reading 'id')",
                'stacktrace' => ['frames' => [
                    ['filename' => 'app://bundle.js', 'function' => 'renderList', 'lineno' => 1, 'colno' => 8421, 'in_app' => true],
                    ['filename' => 'app://bundle.js', 'function' => 'renderRow', 'lineno' => 1, 'colno' => 9002, 'in_app' => true],
                ]],
            ]]],
            'sdk' => ['name' => 'sentry.javascript.browser', 'version' => '8.0.0'],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->firstOrFail();

        $this->assertSame('javascript', $event->platform);
        $this->assertCount(2, $event->frames());
        $this->assertSame(9002, data_get($event->frames(), '1.colno'));
        $this->assertSame('renderRow (app://bundle.js)', $event->culprit);
    }

    public function test_the_trace_of_a_report_is_kept_in_its_own_columns(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, [
            'message' => 'in einem Aufruf passiert',
            'contexts' => ['trace' => [
                // Groß geschrieben, wie es manche SDKs tun. Bliebe die
                // Schreibweise stehen, fänden Fehler und Transaktion desselben
                // Vorgangs nicht zueinander.
                'trace_id' => 'AABBCCDDEEFF00112233445566778899',
                'span_id' => 'AABBCCDDEEFF0011',
            ]],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->firstOrFail();

        // Die Spalten sind der Weg der Trace-Ansicht (PF4) zu den Fehlern eines
        // Ablaufs; das Fach in `contexts` bleibt daneben unverändert stehen.
        $this->assertSame('aabbccddeeff00112233445566778899', $event->trace_id);
        $this->assertSame('aabbccddeeff0011', $event->trace_span_id);
        $this->assertSame('aabbccddeeff00112233445566778899', data_get($event->contexts, 'trace.trace_id'));
    }

    public function test_a_report_without_a_trace_keeps_the_columns_empty(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, ['message' => 'ohne Aufruf']);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->firstOrFail();

        // Ein SDK ohne Performance-Aufzeichnung schickt keine Spur. Das ist der
        // Regelfall und kein Datenfehler.
        $this->assertNull($event->trace_id);
        $this->assertNull($event->trace_span_id);
    }

    public function test_a_badly_filled_report_is_stored_with_a_note_instead_of_being_dropped(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, [
            'message' => 'noch lesbar',
            'request' => 'keine Anfrage, sondern Text',
            'contexts' => 17,
        ]);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->firstOrFail();

        // Die kaputtesten Meldungen kommen aus den kaputtesten Anwendungen —
        // also genau von dort, wo jemand nachsehen will.
        $this->assertSame('noch lesbar', $event->title);
        $this->assertNull($event->request);
        $this->assertTrue($event->wasReduced());
        $this->assertContains('request', data_get($event->notes, 'invalid', []));
        $this->assertSame(ProcessingState::Processed, $payload->fresh()?->processing_state);
    }

    public function test_a_second_run_replaces_the_record_instead_of_adding_one(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, ['message' => 'erster Lauf']);

        ProcessIngestPayload::dispatch($payload);

        $first = Event::query()->firstOrFail();

        // Wie nach einer Verbesserung an einem Schritt: dieselbe Meldung
        // zurück in den Rückstand und noch einmal durch die Kette.
        $payload->resetProcessing();
        ProcessIngestPayload::dispatch($payload->fresh());

        $this->assertSame(1, Event::query()->count());
        $this->assertSame($first->id, Event::query()->firstOrFail()->id);
    }

    public function test_a_non_error_item_passes_through_without_an_event(): void
    {
        $key = $this->key();

        $payload = $this->accept($key, ['sid' => 'abc', 'status' => 'ok'], IngestType::Session);

        ProcessIngestPayload::dispatch($payload);

        // Eine Sitzung ist kein Fehler. Sie wird durchgereicht und **nicht**
        // ausgesondert — sie gehört nur einem anderen Schritt.
        $this->assertSame(0, Event::query()->count());
        $this->assertSame(ProcessingState::Processed, $payload->fresh()?->processing_state);
    }

    public function test_the_normalized_event_is_available_to_the_following_steps(): void
    {
        CapturingStep::reset();

        config(['ingest.processing.steps' => [
            DecodePayload::class,
            NormalizeEvent::class,
            CapturingStep::class,
        ]]);

        $key = $this->key();

        $payload = $this->accept($key, ['message' => 'für den nächsten Schritt']);

        ProcessIngestPayload::dispatch($payload);

        // Was I5 und I6 vorfinden werden: den Datensatz unter einem
        // festgelegten Namen. Das ist die Zusage dieses Schritts an die
        // folgenden — sie sollen ihn abholen und nicht selbst anlegen müssen.
        $context = CapturingStep::$last;

        $this->assertNotNull($context);

        $normalized = $context->get(NormalizeEvent::RESULT);

        $this->assertInstanceOf(NormalizedEvent::class, $normalized);
        $this->assertSame('für den nächsten Schritt', $normalized->title);

        $record = $context->get(NormalizeEvent::RESULT.'_record');

        $this->assertInstanceOf(Event::class, $record);
        $this->assertSame($payload->id, $record->ingest_payload_id);
    }
}
