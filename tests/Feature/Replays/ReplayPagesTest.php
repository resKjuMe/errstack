<?php

namespace Tests\Feature\Replays;

use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Replay;
use App\Models\ReplayError;
use App\Models\User;
use App\Support\Replays\ReplayRecording;
use App\Support\Replays\ReplayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Replays\ReplayPayload;
use Tests\TestCase;

/**
 * Die Ansichten der Aufzeichnungen: Liste, Abspielseite, Bilddaten — und der
 * Weg von einem Fehler hierher.
 *
 * Der Weg vom Fehler ist ausdrücklich mitgeprüft. Er ist die Zusage an die
 * Fehlerdetailseite: dort steht eine Meldung, und ein Link darauf muss hier
 * ankommen — auch dann, wenn es zu genau dieser Meldung keine Aufzeichnung
 * gibt. Ein Link, der davon abhängt, ob die Aufnahme gerade lief, wäre keiner.
 *
 * Ebenso mitgeprüft: dass eine Aufzeichnung eines fremden Hauses nicht
 * abspielbar ist. Ein Stacktrace zeigt Code, eine Aufzeichnung den Bildschirm
 * eines Menschen — die Rechtefrage hängt hier an jedem Weg, auch am Datenstrom
 * mit den Bilddaten.
 */
class ReplayPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $organization = Organization::factory()->withMember($this->user)->create();
        $this->project = Project::factory()->for($organization)->create();

        $this->user->switchOrganization($organization);
    }

    /**
     * Die Nutzlast der Inertia-Seite.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');
        $props = is_array($page) ? ($page['props'] ?? []) : [];

        return is_array($props) ? $props : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function replay(array $attributes = []): Replay
    {
        return Replay::factory()->create($attributes + [
            'project_id' => $this->project->id,
            'started_at' => now()->subMinutes(5),
            'last_segment_at' => now()->subMinutes(4),
        ]);
    }

    /**
     * Eine Aufzeichnung mit echten Bilddaten auf der Platte — nötig überall
     * dort, wo der Datenstrom geprüft wird.
     */
    private function withSegment(Replay $replay): Replay
    {
        $recording = ReplayRecording::fromBytes(
            ReplayPayload::recording(0, ReplayPayload::events($replay->started_at->getTimestampMs())),
            null,
            20000,
        );

        app(ReplayStore::class)->put($replay, $recording);

        return $replay->refresh();
    }

    /**
     * Eine Fehlermeldung samt Eintrag, wie die Detailseite sie zeigt.
     */
    private function event(): Event
    {
        $issue = Issue::factory()->create(['project_id' => $this->project->id]);
        $group = EventGroup::factory()->create([
            'project_id' => $this->project->id,
            'issue_id' => $issue->id,
        ]);

        return Event::factory()->create([
            'project_id' => $this->project->id,
            'event_group_id' => $group->id,
            'event_id' => IngestPayload::freshEventId(),
        ]);
    }

    public function test_the_list_shows_the_replays_of_the_period(): void
    {
        $replay = $this->replay();

        $props = $this->props(
            $this->actingAs($this->user)->get(route('replays.index', ['tz' => 'UTC']))
        );

        $this->assertCount(1, $props['replays']);
        $this->assertSame($replay->id, $props['replays'][0]['id']);
        $this->assertSame(1, $props['total']);
    }

    /**
     * Eine Zeile ohne Abschnitte ist ein Anker für eine Verknüpfung und kein
     * Film. Sie in der Liste zu zeigen hieße, zu einem leeren Abspieler
     * einzuladen.
     */
    public function test_a_replay_without_segments_stays_out_of_the_list(): void
    {
        $this->replay(['segment_count' => 0, 'event_count' => 0]);

        $props = $this->props(
            $this->actingAs($this->user)->get(route('replays.index', ['tz' => 'UTC']))
        );

        $this->assertSame([], $props['replays']);
        $this->assertSame(0, $props['total']);
    }

    public function test_the_list_can_be_narrowed_to_sessions_with_errors(): void
    {
        $this->replay(['error_count' => 0]);
        $withError = $this->replay(['error_count' => 2]);

        $props = $this->props(
            $this->actingAs($this->user)->get(route('replays.index', ['errors' => 1, 'tz' => 'UTC']))
        );

        $this->assertCount(1, $props['replays']);
        $this->assertSame($withError->id, $props['replays'][0]['id']);
    }

    public function test_the_detail_page_carries_the_jump_marks(): void
    {
        $replay = $this->replay();
        $event = $this->event();

        ReplayError::link($replay, $event->event_id, $replay->started_at->addSeconds(30));

        $props = $this->props(
            $this->actingAs($this->user)->get(route('replays.show', $replay))
        );

        $this->assertSame($replay->replay_id, $props['replay']['replayId']);
        $this->assertCount(1, $props['errors']);
        $this->assertSame($event->event_id, $props['errors'][0]['eventId']);
        // Der Abstand zum Beginn — der Wert, mit dem der Abspieler springt.
        $this->assertSame(30_000, $props['errors'][0]['offsetMs']);
        $this->assertNotNull($props['errors'][0]['href']);
    }

    /**
     * Ein Verweis darf ins Leere zeigen: die Verknüpfung führt eine
     * Ereignis-Nummer und keinen Fremdschlüssel, und die Aufbewahrungsfristen
     * beider Bestände sind verschieden. Eine Marke ohne Ziel wäre die Einladung
     * zu einem Klick, der nirgendwo hinführt.
     */
    public function test_a_mark_without_its_event_is_left_out(): void
    {
        $replay = $this->replay();

        ReplayError::link($replay, IngestPayload::freshEventId(), now()->toImmutable());

        $props = $this->props(
            $this->actingAs($this->user)->get(route('replays.show', $replay))
        );

        $this->assertSame([], $props['errors']);
    }

    public function test_the_recording_is_streamed_with_its_timeline(): void
    {
        $replay = $this->withSegment($this->replay());

        $response = $this->actingAs($this->user)->get(route('replays.data', $replay));

        $response->assertOk();

        $data = json_decode($response->streamedContent(), true);

        $this->assertCount(5, $data['events']);
        $this->assertSame(ReplayPayload::TYPE_CUSTOM, $data['events'][0]['type']);
        $this->assertCount(1, $data['timeline']['console']);
        $this->assertCount(1, $data['timeline']['network']);
    }

    public function test_an_error_leads_to_its_replay(): void
    {
        $replay = $this->replay();
        $event = $this->event();

        ReplayError::link($replay, $event->event_id, now()->toImmutable());

        $this->actingAs($this->user)
            ->get(route('replays.event', [$event->group->issue, $event]))
            ->assertRedirect(route('replays.show', $replay));
    }

    /**
     * Ohne Aufzeichnung führt der Weg in die Liste und nicht in eine
     * Fehlerseite: „für diese Meldung haben wir keine, hier sind die anderen"
     * ist die nützlichere Antwort.
     */
    public function test_an_error_without_a_replay_leads_to_the_list(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)
            ->get(route('replays.event', [$event->group->issue, $event]))
            ->assertRedirect(route('replays.index'));
    }

    public function test_the_issue_page_shows_the_replays_of_the_event(): void
    {
        $replay = $this->replay();
        $event = $this->event();

        ReplayError::link($replay, $event->event_id, now()->toImmutable());

        $props = $this->props(
            $this->actingAs($this->user)->get(route('issues.events.show', [$event->group->issue, $event]))
        );

        $this->assertCount(1, $props['replays']);
        $this->assertSame($replay->id, $props['replays'][0]['id']);
    }

    public function test_a_stranger_sees_neither_the_page_nor_the_recording(): void
    {
        $stranger = User::factory()->create();
        $other = Organization::factory()->withMember($stranger)->create();
        $stranger->switchOrganization($other);

        $replay = $this->withSegment($this->replay());

        $this->actingAs($stranger)->get(route('replays.show', $replay))->assertForbidden();
        $this->actingAs($stranger)->get(route('replays.data', $replay))->assertForbidden();
    }
}
