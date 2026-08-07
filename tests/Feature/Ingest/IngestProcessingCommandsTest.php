<?php

namespace Tests\Feature\Ingest;

use App\Enums\ProcessingState;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ingest\FailingStep;
use Tests\TestCase;

/**
 * Die beiden Kommandos, mit denen sich die Verarbeitung im Betrieb bedienen
 * lässt: nachsehen, wie weit sie mitkommt — und Liegengebliebenes noch einmal
 * laufen lassen.
 */
class IngestProcessingCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FailingStep::failTimes(0);
    }

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    private function failedPayload(ProjectKey $key): IngestPayload
    {
        $payload = IngestPayload::factory()->viaKey($key)->create();

        $payload->finishProcessing(ProcessingState::Failed, 12, 5, 'Datenbank war weg.');

        return $payload;
    }

    /**
     * Der Punkt der Aufgabe: was endgültig gescheitert ist, muss ohne
     * Datenbank-Handarbeit wieder in die Verarbeitung kommen.
     */
    public function test_failed_reports_can_be_processed_again(): void
    {
        $payload = $this->failedPayload($this->key());

        $this->artisan('ingest:retry')->assertSuccessful();

        // Die Warteschlange läuft im Test unmittelbar — nach dem Einreihen ist
        // die Meldung also schon durch.
        $this->assertSame(ProcessingState::Processed, $payload->refresh()->processing_state);
    }

    public function test_retrying_can_be_limited_to_a_single_report(): void
    {
        $key = $this->key();

        $wanted = $this->failedPayload($key);
        $untouched = $this->failedPayload($key);

        $this->artisan('ingest:retry', ['--id' => [$wanted->id]])->assertSuccessful();

        $this->assertSame(ProcessingState::Processed, $wanted->refresh()->processing_state);
        $this->assertSame(ProcessingState::Failed, $untouched->refresh()->processing_state);
    }

    public function test_retrying_can_be_limited_to_one_project(): void
    {
        $mine = $this->failedPayload($this->key());
        $other = $this->failedPayload($this->key());

        $this->artisan('ingest:retry', ['--project' => $mine->project_id])->assertSuccessful();

        $this->assertSame(ProcessingState::Processed, $mine->refresh()->processing_state);
        $this->assertSame(ProcessingState::Failed, $other->refresh()->processing_state);
    }

    public function test_retrying_without_anything_to_do_says_so(): void
    {
        $this->artisan('ingest:retry')
            ->expectsOutputToContain('Keine gescheiterten Meldungen gefunden.')
            ->assertSuccessful();
    }

    public function test_the_status_command_reports_backlog_and_failures(): void
    {
        $key = $this->key();

        IngestPayload::factory()->viaKey($key)->create();
        $this->failedPayload($key);

        $this->artisan('ingest:status')
            ->expectsOutputToContain('Rückstand')
            ->expectsOutputToContain('Endgültig gescheitert')
            ->assertSuccessful();
    }
}
