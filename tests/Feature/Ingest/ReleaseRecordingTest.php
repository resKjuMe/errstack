<?php

namespace Tests\Feature\Ingest;

use App\Enums\IngestType;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Performance\TransactionPayload;
use Tests\TestCase;

/**
 * Das Erfassen der ausgelieferten Version, von der angenommenen Meldung bis zu
 * den beiden Angaben am Fehler-Eintrag.
 *
 * Die Frage dahinter ist die, mit der nach einer Auslieferung als Erstes jemand
 * kommt: **war das schon vor dem Deploy so?** Sie wird hier beantwortet — und
 * zwar auch dann, wenn die Meldungen nicht in der Reihenfolge eintreffen, in
 * der sie entstanden sind.
 */
class ReleaseRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein fester Zeitpunkt: die Version merkt sich erstes und letztes
        // Auftreten, und ein Test, der um Mitternacht anders ausgeht, ist keiner.
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * Nimmt eine Fehlermeldung an und lässt sie durch die Kette laufen.
     */
    private function ingest(Project $project, ?string $release, ?Carbon $at = null): Event
    {
        $eventId = IngestPayload::freshEventId();

        $body = [
            'event_id' => $eventId,
            'timestamp' => ($at ?? Carbon::now())->toIso8601String(),
            'platform' => 'php',
            'exception' => ['values' => [[
                'type' => 'RuntimeException',
                'value' => 'Rechnung konnte nicht erstellt werden',
                'stacktrace' => ['frames' => [[
                    'filename' => 'app/Http/Controllers/InvoiceController.php',
                    'function' => 'store',
                    'lineno' => 42,
                    'in_app' => true,
                ]]],
            ]]],
        ];

        if ($release !== null) {
            $body['release'] = $release;
        }

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode($body),
        ]);

        ProcessIngestPayload::dispatch($payload);

        return Event::query()->where('ingest_payload_id', $payload->id)->sole();
    }

    /**
     * Der Fall aus der Aufgabenstellung: derselbe Fehler in zwei Versionen.
     */
    public function test_an_issue_names_its_first_and_last_version(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, '1.0.0', Carbon::now()->subHours(3));
        $this->ingest($project, '1.1.0', Carbon::now()->subHour());

        $issue = Issue::query()->sole();

        $this->assertSame('1.0.0', $issue->firstRelease?->version);
        $this->assertSame('1.1.0', $issue->lastRelease?->version);
    }

    public function test_a_version_appears_on_its_own_with_first_and_last_event(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, '1.0.0', Carbon::now()->subHours(3));
        $this->ingest($project, '1.0.0', Carbon::now()->subHour());

        $release = Release::query()->where('project_id', $project->id)->sole();

        $this->assertSame('1.0.0', $release->version);
        $this->assertNotNull($release->first_event_at);
        $this->assertNotNull($release->last_event_at);
        $this->assertTrue($release->first_event_at->equalTo(Carbon::now()->subHours(3)));
        $this->assertTrue($release->last_event_at->equalTo(Carbon::now()->subHour()));

        // Die Zerlegung steht daneben, damit die Liste semantisch sortieren kann.
        $this->assertSame(1, $release->sort_major);
        $this->assertSame(0, $release->sort_minor);
        $this->assertSame(0, $release->sort_patch);
    }

    /**
     * Eine nachgereichte alte Meldung darf die zuletzt betroffene Version nicht
     * zurückdrehen — sie darf aber die zuerst betroffene nach vorn holen.
     */
    public function test_a_late_arriving_old_event_does_not_move_the_last_version(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, '1.1.0', Carbon::now()->subHour());
        $this->ingest($project, '0.9.0', Carbon::now()->subDays(2));

        $issue = Issue::query()->sole();

        $this->assertSame('0.9.0', $issue->firstRelease?->version);
        $this->assertSame('1.1.0', $issue->lastRelease?->version);
    }

    /**
     * Zwei Meldungen mit demselben Zeitstempel aus verschiedenen Versionen: die
     * zuerst gesehene bleibt die erste, die zuletzt verarbeitete wird die
     * letzte. Ohne diese Unterscheidung würde die zweite Meldung beide Angaben
     * auf sich ziehen und die erste Version verschwinden lassen.
     */
    public function test_two_events_at_the_same_moment_keep_the_earlier_first_version(): void
    {
        $project = Project::factory()->create();

        $at = Carbon::now()->subHour();

        $this->ingest($project, '1.0.0', $at);
        $this->ingest($project, '1.1.0', $at);

        $issue = Issue::query()->sole();

        $this->assertSame('1.0.0', $issue->firstRelease?->version);
        $this->assertSame('1.1.0', $issue->lastRelease?->version);
    }

    /**
     * Ohne Angabe entsteht keine Version — und schon gar keine mit einem
     * erfundenen Namen.
     */
    public function test_an_event_without_a_version_creates_nothing(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, null);

        $this->assertSame(0, Release::query()->count());

        $issue = Issue::query()->sole();

        $this->assertNull($issue->first_release_id);
        $this->assertNull($issue->last_release_id);
    }

    /**
     * Dieselbe Versionsangabe in zwei Projekten sind zwei Versionen: eine
     * Auslieferung gehört zu einer Anwendung.
     */
    public function test_the_same_version_in_two_projects_stays_apart(): void
    {
        $one = Project::factory()->create();
        $two = Project::factory()->create();

        $this->ingest($one, '1.0.0');
        $this->ingest($two, '1.0.0');

        $this->assertSame(2, Release::query()->where('version', '1.0.0')->count());
    }

    /**
     * Eine Auslieferung, aus der bislang nur Antwortzeiten eintrafen, ist eine
     * erfolgreiche Auslieferung — und gehört genauso in die Liste.
     */
    public function test_a_transaction_records_its_version_too(): void
    {
        $key = Project::factory()->create()->keys()->firstOrFail();

        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body(TransactionPayload::make(['release' => 'errstack@2.0.0']), IngestType::Transaction)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        $release = Release::query()->where('project_id', $key->project_id)->sole();

        $this->assertSame('errstack@2.0.0', $release->version);
        $this->assertNotNull($release->first_event_at);

        // Eine Transaktion ist kein Fehler und legt auch keinen an.
        $this->assertSame(0, Issue::query()->count());
    }

    /**
     * Dieselbe Meldung ein zweites Mal durch die Kette — nach einem Fehlschlag,
     * nach einer Verbesserung an einem Schritt — darf nichts verdoppeln.
     */
    public function test_processing_the_same_payload_twice_keeps_one_version(): void
    {
        $project = Project::factory()->create();

        $eventId = IngestPayload::freshEventId();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode([
                'event_id' => $eventId,
                'timestamp' => Carbon::now()->toIso8601String(),
                'platform' => 'php',
                'release' => '1.0.0',
                'message' => 'Zahlung abgelehnt',
            ]),
        ]);

        ProcessIngestPayload::dispatch($payload);
        ProcessIngestPayload::dispatch($payload->refresh());

        $this->assertSame(1, Release::query()->count());

        $issue = Issue::query()->sole();

        $this->assertSame('1.0.0', $issue->firstRelease?->version);
        $this->assertSame('1.0.0', $issue->lastRelease?->version);
    }

    /**
     * Zwei Arbeiter, dieselbe frische Version: die Datenbank entscheidet, und
     * es bleibt bei einer Zeile.
     */
    public function test_two_workers_on_the_same_fresh_version_create_one_row(): void
    {
        $project = Project::factory()->create();

        $first = Release::forVersion($project, '3.0.0');
        $second = Release::forVersion($project, '3.0.0');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Release::query()->count());
    }

    /**
     * Eine über die Schnittstelle angekündigte Version hat noch kein Ereignis —
     * und bekommt beim ersten einen Zeitstempel, statt bei `null` zu bleiben.
     */
    public function test_an_announced_version_gets_its_first_event(): void
    {
        $project = Project::factory()->create();

        $release = Release::forVersion($project, '4.0.0');

        $this->assertNull($release->first_event_at);

        $this->ingest($project, '4.0.0');

        $this->assertNotNull($release->refresh()->first_event_at);
    }

    /**
     * Eine Versionsangabe wird vereinheitlicht: „1.0.0" und „ 1.0.0 " sind
     * dieselbe Auslieferung.
     */
    public function test_surrounding_whitespace_does_not_create_a_second_version(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, '1.0.0');
        $this->ingest($project, '  1.0.0  ');

        $this->assertSame(1, Release::query()->count());
    }
}
