<?php

namespace Tests\Feature\Replays;

use App\Models\Project;
use App\Models\Replay;
use App\Models\ReplaySegment;
use App\Support\Replays\ReplayRecording;
use App\Support\Replays\ReplayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Replays\ReplayPayload;
use Tests\TestCase;

/**
 * Die Aufbewahrung der Aufzeichnungen — die zweite Hälfte der Zusage.
 *
 * Eine Frist, die niemand durchsetzt, ist eine Absichtserklärung. Und nirgends
 * wiegt das so schwer wie hier: eine Aufzeichnung ist der Bildschirm eines
 * Menschen und zugleich das Schwerste, was diese Anwendung speichert.
 *
 * Geprüft wird deshalb nicht nur, dass die Zeile verschwindet, sondern dass die
 * **Dateien** verschwinden — samt denen von Projekten, die es gar nicht mehr
 * gibt. Eine Kaskade in der Datenbank erreicht kein Laufwerk, und der Weg zu
 * diesen Dateien führte über die gelöschten Zeilen.
 */
class ReplayRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function replayWithSegment(Project $project, int $ageInDays): Replay
    {
        $startedAt = now()->subDays($ageInDays);

        $replay = Replay::factory()->create([
            'project_id' => $project->id,
            'started_at' => $startedAt,
            'last_segment_at' => $startedAt->copy()->addMinute(),
            'segment_count' => 0,
            'event_count' => 0,
            'size_bytes' => 0,
        ]);

        $recording = ReplayRecording::fromBytes(
            ReplayPayload::recording(0, ReplayPayload::events($startedAt->getTimestampMs())),
            null,
            20000,
        );

        app(ReplayStore::class)->put($replay, $recording);

        return $replay->refresh();
    }

    public function test_expired_replays_lose_their_rows_and_their_files(): void
    {
        config()->set('replays.retention_days', 30);

        $project = Project::factory()->create();
        $old = $this->replayWithSegment($project, 40);
        $fresh = $this->replayWithSegment($project, 2);

        $oldPath = $old->segments()->sole()->path;
        $freshPath = $fresh->segments()->sole()->path;

        $this->artisan('replays:sweep')->assertSuccessful();

        $this->assertNull(Replay::query()->find($old->id));
        $this->assertNotNull(Replay::query()->find($fresh->id));
        $this->assertSame(1, ReplaySegment::query()->count());

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($freshPath);
    }

    /**
     * Die Frist des Projekts schlägt die des Betreibers — das ist der ganze
     * Sinn einer eigenen Spalte.
     */
    public function test_a_project_may_keep_replays_longer_than_the_operator_default(): void
    {
        config()->set('replays.retention_days', 7);

        $project = Project::factory()->create(['replay_retention_days' => 60]);
        $replay = $this->replayWithSegment($project, 30);

        $this->artisan('replays:sweep')->assertSuccessful();

        $this->assertNotNull(Replay::query()->find($replay->id));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        config()->set('replays.retention_days', 1);

        $project = Project::factory()->create();
        $replay = $this->replayWithSegment($project, 10);
        $path = $replay->segments()->sole()->path;

        $this->artisan('replays:sweep', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(Replay::query()->find($replay->id));
        Storage::disk('local')->assertExists($path);
    }

    /**
     * Ein gelöschtes Projekt nimmt seine Zeilen über den Fremdschlüssel mit —
     * die Dateien nicht. Ohne diesen Schritt bliebe der schwerste Teil der
     * Anwendung für immer und unauffindbar liegen.
     */
    public function test_files_of_deleted_projects_are_swept_too(): void
    {
        $project = Project::factory()->create();
        $replay = $this->replayWithSegment($project, 1);
        $path = $replay->segments()->sole()->path;

        $project->delete();

        $this->assertSame(0, Replay::query()->count());
        Storage::disk('local')->assertExists($path);

        $this->artisan('replays:sweep')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
    }
}
