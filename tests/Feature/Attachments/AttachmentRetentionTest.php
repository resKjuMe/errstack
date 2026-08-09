<?php

namespace Tests\Feature\Attachments;

use App\Enums\IngestType;
use App\Models\EventAttachment;
use App\Models\IngestPayload;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Das Aufräumen abgelaufener Anhänge (`attachments:prune`).
 *
 * Es ist die Gegenseite zur Zusage auf der Fehlerseite („verfällt am …"): ohne
 * diesen Durchlauf wäre die Frist eine Behauptung. Geprüft wird deshalb beides —
 * dass die Zeile weg ist **und** dass die Datei vom Laufwerk verschwindet. Nur die
 * Zeile zu löschen wäre der Fehler, den niemand bemerkt, bis die Platte voll ist.
 */
class AttachmentRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function attachment(Project $project, int $daysAgo, string $content): EventAttachment
    {
        $checksum = sha1($content);

        /** @var EventAttachment $attachment */
        $attachment = EventAttachment::factory()
            ->for($project)
            ->receivedDaysAgo($daysAgo)
            ->create([
                'size' => strlen($content),
                'checksum' => $checksum,
                'path' => 'event-attachments/'.$project->id.'/'.substr($checksum, 0, 2).'/'.$checksum,
            ]);

        Storage::disk('local')->put($attachment->path, $content);

        return $attachment;
    }

    public function test_expired_attachments_are_deleted_with_their_files(): void
    {
        $project = Project::factory()->create(['attachment_retention_days' => 7]);

        $old = $this->attachment($project, 9, 'alt');
        $fresh = $this->attachment($project, 2, 'frisch');

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($fresh->fresh());

        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($fresh->path);
    }

    public function test_every_project_is_measured_against_its_own_retention(): void
    {
        // Der Kern der eigenen Frist: dieselbe Datei, gleich alt, zwei Projekte
        // mit verschiedenen Einstellungen — und nur eine fällt weg.
        $strict = Project::factory()->create(['attachment_retention_days' => 3]);
        $lenient = Project::factory()->create(['attachment_retention_days' => 30]);

        $expired = $this->attachment($strict, 5, 'im strengen Projekt');
        $kept = $this->attachment($lenient, 5, 'im nachsichtigen Projekt');

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertNull($expired->fresh());
        $this->assertNotNull($kept->fresh());
    }

    public function test_a_shared_file_survives_as_long_as_one_row_points_at_it(): void
    {
        $project = Project::factory()->create(['attachment_retention_days' => 7]);

        // Dieselbe Datei an zwei Meldungen — der Regelfall bei einem
        // Absturzdialog mit „erneut versuchen". Eine ist abgelaufen, die andere
        // nicht.
        $expired = $this->attachment($project, 9, 'derselbe Screenshot');
        $kept = $this->attachment($project, 1, 'derselbe Screenshot');

        $this->assertSame($expired->path, $kept->path);

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertNull($expired->fresh());
        $this->assertNotNull($kept->fresh());
        Storage::disk('local')->assertExists($kept->path);
    }

    public function test_a_run_without_anything_to_do_reports_zero(): void
    {
        Project::factory()->create(['attachment_retention_days' => 7]);

        $this->artisan('attachments:prune')
            ->expectsOutputToContain('0 Anhänge gelöscht')
            ->assertSuccessful();
    }

    public function test_only_actually_freed_space_is_reported(): void
    {
        $project = Project::factory()->create(['attachment_retention_days' => 7]);

        // Zwei abgelaufene Zeilen auf **einen** Inhalt: gelöscht werden zwei
        // Anhänge, freigegeben wird der Platz einmal. Eine Meldung, die beides
        // gleichsetzt, ließe einen Betreiber suchen, warum der Verbrauch nicht
        // sinkt.
        $this->attachment($project, 9, 'derselbe Inhalt');
        $this->attachment($project, 9, 'derselbe Inhalt');

        $this->artisan('attachments:prune')
            ->expectsOutputToContain('2 Anhänge gelöscht, 15 B freigegeben')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_raw_payload_goes_with_the_attachment(): void
    {
        $project = Project::factory()->create(['attachment_retention_days' => 7]);
        $payload = IngestPayload::factory()
            ->for($project)
            ->bytes('geheim', IngestType::Attachment, itemHeaders: ['filename' => 'a.txt', 'content_type' => 'text/plain'])
            ->create();

        $attachment = $this->attachment($project, 9, 'geheim');
        $attachment->forceFill(['ingest_payload_id' => $payload->id])->save();

        $this->artisan('attachments:prune')->assertSuccessful();

        // Ohne diesen Schritt bliebe eine zweite Kopie derselben Bytes in der
        // Eingangsablage stehen — und ein erneut eingereihter Beleg legte den
        // weggeräumten Anhang wieder an.
        $this->assertNull($attachment->fresh());
        $this->assertNull($payload->fresh());
    }

    public function test_deleting_a_project_takes_its_files_along(): void
    {
        $project = Project::factory()->create(['attachment_retention_days' => 7]);
        $attachment = $this->attachment($project, 1, 'im Projekt');

        // Der Fremdschlüssel kaskadiert: ohne eigenes Aufräumen wären die Zeilen
        // weg und die Dateien für das nächtliche Aufräumen unerreichbar.
        $project->delete();

        $this->assertNull($attachment->fresh());
        Storage::disk('local')->assertMissing($attachment->path);
    }
}
