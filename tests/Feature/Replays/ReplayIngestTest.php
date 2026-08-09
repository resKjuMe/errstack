<?php

namespace Tests\Feature\Replays;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Replay;
use App\Models\ReplayError;
use App\Models\ReplaySegment;
use App\Support\Replays\ReplayStore;
use App\Support\Replays\ReplayTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Replays\ReplayPayload;
use Tests\TestCase;

/**
 * Die Aufnahme von Sitzungs-Aufzeichnungen, vom angenommenen Envelope-Element
 * bis zur abgelegten Zeile und der Datei auf der Platte.
 *
 * Geprüft wird, was die Abspielseite voraussetzt: dass die beiden Hälften einer
 * Aufzeichnung in **beliebiger** Reihenfolge zueinanderfinden, dass die
 * Abschnitte vollständig und in Abspielreihenfolge zusammengesetzt werden, dass
 * ein Fehler seine Sitzung findet und umgekehrt — und dass die Zusagen zum
 * Datenschutz nicht bloß im Kommentar stehen: keine Aufzeichnung bei Frist null,
 * kein stiller Verlust durch den Anhang-Schalter, ein sichtbarer Vermerk bei
 * fehlender Maskierung.
 */
class ReplayIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein vorgetäuschtes Laufwerk: die Bilddaten sollen wirklich
        // geschrieben werden — nur eben nicht dorthin, wo sie liegen bleiben.
        Storage::fake('local');
    }

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ingest(array $body, IngestType $type, ProjectKey $key, ?string $eventId = null): IngestPayload
    {
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body($body, $type)
            ->create($eventId === null ? [] : ['event_id' => $eventId]);

        ProcessIngestPayload::dispatch($payload);

        return $payload->refresh();
    }

    private function ingestSegment(string $raw, ProjectKey $key, string $replayId): IngestPayload
    {
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->bytes($raw, IngestType::ReplayRecording, $replayId)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        return $payload->refresh();
    }

    public function test_header_and_recording_become_one_replay(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(30)->getTimestampMs();

        $this->ingest(ReplayPayload::header($replayId), IngestType::ReplayEvent, $key, $replayId);
        $segment = $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs)),
            $key,
            $replayId,
        );

        $replay = Replay::query()->sole();

        $this->assertSame(ProcessingState::Processed, $segment->processing_state);
        $this->assertSame($replayId, $replay->replay_id);
        $this->assertSame('production', $replay->environment);
        $this->assertSame('1.4.0', $replay->release);
        $this->assertSame('Chrome 120', $replay->browser);
        $this->assertSame(['id' => '4711'], $replay->user);
        $this->assertSame(1, $replay->segment_count);
        $this->assertSame(5, $replay->event_count);
        $this->assertTrue($replay->masked);
        $this->assertGreaterThan(0, $replay->size_bytes);
    }

    /**
     * Der Regelfall bei mehreren Arbeitern: der Abschnitt ist vor den Kopfdaten
     * da. Er darf die Aufzeichnung nicht kosten — und die Kopfdaten müssen
     * danach noch eintragen können, was nur sie wissen.
     */
    public function test_a_recording_may_arrive_before_its_header(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(30)->getTimestampMs();

        $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs)),
            $key,
            $replayId,
        );

        $this->assertSame(1, Replay::query()->sole()->segment_count);

        $this->ingest(ReplayPayload::header($replayId), IngestType::ReplayEvent, $key, $replayId);

        $replay = Replay::query()->sole();

        $this->assertSame('1.4.0', $replay->release);
        $this->assertSame('https://example.com/kasse', $replay->url);
        $this->assertSame(1, $replay->segment_count);
    }

    public function test_segments_are_stored_compressed_and_replayed_in_order(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(30)->getTimestampMs();

        // Absichtlich in verkehrter Reihenfolge eingespielt: Abschnitte
        // überholen einander in der Warteschlange, und die Abspielreihenfolge
        // ist die Nummer und nicht die Ankunft.
        $this->ingestSegment(
            ReplayPayload::recording(1, ReplayPayload::events($startMs + 5_000)),
            $key,
            $replayId,
        );
        $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs)),
            $key,
            $replayId,
        );

        $replay = Replay::query()->sole();
        $segments = $replay->segments()->get();

        $this->assertSame([0, 1], $segments->pluck('segment_id')->all());
        $this->assertSame(2, $replay->segment_count);

        $store = app(ReplayStore::class);

        foreach ($segments as $segment) {
            Storage::disk('local')->assertExists($segment->path);
            $this->assertCount(5, $store->segmentEvents($segment));
        }

        $this->assertSame(
            $startMs,
            $segments->first()->started_at->getTimestampMs(),
        );
    }

    /**
     * Ein Abschnitt darf auch ungepackt kommen — das SDK lässt sich so
     * einstellen, und ältere Fassungen tun es von sich aus.
     */
    public function test_an_uncompressed_recording_is_accepted(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(10)->getTimestampMs();

        $payload = $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs), compress: false),
            $key,
            $replayId,
        );

        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertSame(1, ReplaySegment::query()->count());
    }

    /**
     * Beide Richtungen der Verknüpfung, in beiden Reihenfolgen — das ist der
     * Kern der Zusage „Fehler zeigen zugehörige Replays und umgekehrt".
     */
    public function test_an_error_finds_its_replay_even_when_it_arrives_first(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(30)->getTimestampMs();

        $error = ReplayPayload::errorEvent($replayId);
        $this->ingest($error, IngestType::Event, $key);

        // Vor der Aufnahme angelegt: die Zeile ist ein Anker und noch kein Film.
        $this->assertSame(0, Replay::query()->sole()->segment_count);
        $this->assertSame(1, ReplayError::query()->count());

        $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs)),
            $key,
            $replayId,
        );

        $replay = Replay::query()->sole();

        $this->assertSame(1, $replay->segment_count);
        $this->assertSame(1, $replay->error_count);
        $this->assertSame($error['event_id'], ReplayError::query()->sole()->event_id);
    }

    public function test_the_header_links_the_errors_it_reports(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $errorId = IngestPayload::freshEventId();

        $this->ingest(
            ReplayPayload::header($replayId, [$errorId]),
            IngestType::ReplayEvent,
            $key,
            $replayId,
        );

        $this->assertSame(1, Replay::query()->sole()->error_count);
        $this->assertSame($errorId, ReplayError::query()->sole()->event_id);
    }

    /**
     * Beide Seiten melden dieselbe Verknüpfung — die Sitzung darf danach nicht
     * mit zwei Fehlern dastehen.
     */
    public function test_the_same_error_is_counted_once(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $errorId = IngestPayload::freshEventId();

        $this->ingest(ReplayPayload::errorEvent($replayId, $errorId), IngestType::Event, $key);
        $this->ingest(
            ReplayPayload::header($replayId, [$errorId]),
            IngestType::ReplayEvent,
            $key,
            $replayId,
        );

        $this->assertSame(1, ReplayError::query()->count());
        $this->assertSame(1, Replay::query()->sole()->error_count);
    }

    public function test_a_project_without_retention_records_nothing(): void
    {
        $key = $this->key();
        $key->project->update(['replay_retention_days' => 0]);
        $replayId = IngestPayload::freshEventId();

        $payload = $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events(now()->getTimestampMs())),
            $key,
            $replayId,
        );

        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
        $this->assertSame(0, Replay::query()->count());
        $this->assertSame(0, ReplaySegment::query()->count());
        $this->assertTrue(
            IngestDiscard::query()->where('reason', DiscardReason::Discarded)->exists(),
        );
    }

    /**
     * „Anhänge nicht speichern" darf die Aufzeichnungen nicht mitnehmen: sie
     * sind ein eigener Bestand mit eigenem Datenschutzweg. Ohne diese Prüfung
     * bliebe die Abspielseite für datenschutzbewusste Projekte wortlos leer.
     */
    public function test_the_attachment_switch_does_not_silence_replays(): void
    {
        $key = $this->key();
        $key->project->update(['scrub_attachments' => true]);
        $replayId = IngestPayload::freshEventId();

        $payload = $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events(now()->subSeconds(5)->getTimestampMs())),
            $key,
            $replayId,
        );

        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertSame(1, ReplaySegment::query()->count());
    }

    public function test_a_recording_without_masking_is_marked(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();

        $this->ingestSegment(
            ReplayPayload::recording(
                0,
                ReplayPayload::events(now()->subSeconds(5)->getTimestampMs(), masked: false),
            ),
            $key,
            $replayId,
        );

        $this->assertFalse(Replay::query()->sole()->masked);
    }

    public function test_the_operator_may_require_masking(): void
    {
        config()->set('replays.require_masking', true);

        $key = $this->key();
        $replayId = IngestPayload::freshEventId();

        $payload = $this->ingestSegment(
            ReplayPayload::recording(
                0,
                ReplayPayload::events(now()->subSeconds(5)->getTimestampMs(), masked: false),
            ),
            $key,
            $replayId,
        );

        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
        $this->assertSame(0, ReplaySegment::query()->count());
    }

    public function test_a_segment_beyond_the_limit_is_dropped_but_the_replay_survives(): void
    {
        config()->set('replays.max_segments', 1);

        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(30)->getTimestampMs();

        $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs)),
            $key,
            $replayId,
        );

        $second = $this->ingestSegment(
            ReplayPayload::recording(1, ReplayPayload::events($startMs + 5_000)),
            $key,
            $replayId,
        );

        $this->assertSame(ProcessingState::Dropped, $second->processing_state);
        $this->assertSame(1, Replay::query()->sole()->segment_count);
        $this->assertSame(1, ReplaySegment::query()->count());
    }

    /**
     * Ein zweiter Durchlauf derselben Rohdaten — nach einem Fehlschlag oder
     * einer Verbesserung an der Auswertung. Er darf den Abschnitt ersetzen und
     * nicht verdoppeln, und die Kennzahlen der Sitzung dürfen dabei nicht
     * mitwachsen.
     */
    public function test_reprocessing_replaces_a_segment(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(30)->getTimestampMs();

        $payload = $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs)),
            $key,
            $replayId,
        );

        $before = Replay::query()->sole();

        $payload->resetProcessing();
        ProcessIngestPayload::dispatch($payload->fresh());

        $after = Replay::query()->sole();

        $this->assertSame(1, ReplaySegment::query()->count());
        $this->assertSame($before->segment_count, $after->segment_count);
        $this->assertSame($before->event_count, $after->event_count);
        $this->assertSame($before->size_bytes, $after->size_bytes);
    }

    public function test_the_timeline_reads_the_tracks_out_of_the_recording(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();
        $startMs = now()->subSeconds(30)->getTimestampMs();

        $this->ingestSegment(
            ReplayPayload::recording(0, ReplayPayload::events($startMs)),
            $key,
            $replayId,
        );

        $replay = Replay::query()->sole();
        $timeline = ReplayTimeline::forReplay($replay, app(ReplayStore::class));

        $this->assertSame(['navigation', 'ui.click'], array_column($timeline['breadcrumbs'], 'category'));
        $this->assertSame(['Zahlung fehlgeschlagen'], array_column($timeline['console'], 'message'));
        $this->assertSame(402, $timeline['network'][0]['status']);
        $this->assertSame('POST', $timeline['network'][0]['method']);
        $this->assertSame(400, $timeline['network'][0]['durationMs']);

        // Die Abstände sind gegen den Beginn der Aufzeichnung gerechnet — der
        // Wert, mit dem der Abspieler springt.
        $this->assertSame(1_000, $timeline['breadcrumbs'][1]['offsetMs']);
    }

    public function test_an_unreadable_recording_is_discarded_and_counted(): void
    {
        $key = $this->key();
        $replayId = IngestPayload::freshEventId();

        $payload = $this->ingestSegment("{\"segment_id\":0}\nkein json", $key, $replayId);

        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
        $this->assertSame(0, ReplaySegment::query()->count());
        $this->assertTrue(
            IngestDiscard::query()->where('reason', DiscardReason::Unreadable)->exists(),
        );
    }
}
