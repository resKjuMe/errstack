<?php

namespace Tests\Feature\Ingest;

use App\Enums\OrganizationRole;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Support\Releases\CommitImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die automatische Zuweisung an den Autor des verdächtigsten Commits (R4).
 *
 * Der zweite Teil der verdächtigen Commits: der erste zeigt sie an, dieser
 * handelt danach. Geprüft wird vor allem, **wann er nicht** handelt — der
 * Schalter steht je Projekt, greift nur beim ersten Auftreten und rührt eine
 * bestehende Zuständigkeit nicht an.
 */
class SuspectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * Ein Projekt mit einer angekündigten Auslieferung, deren einziger Commit
     * genau die Zeile angefasst hat, an der es gleich knallt.
     *
     * @return array{Project, User}
     */
    private function context(bool $autoAssign): array
    {
        $author = User::factory()->create(['name' => 'Bea Beitragende', 'email' => 'bea@acme.test']);
        $organization = Organization::factory()->create();
        $organization->setRole($author, OrganizationRole::Member);

        $project = Project::factory()->for($organization)->create([
            'auto_assign_suspect_commits' => $autoAssign,
        ]);

        $release = Release::factory()->for($project)->version('1.0.0')->create();

        CommitImport::into($release, [[
            'id' => 'aaaa111bbbb',
            'repository' => 'acme/webshop',
            'message' => 'Rechnung ohne Positionen abfangen',
            'author_email' => 'bea@acme.test',
            'author_name' => 'Bea Beitragende',
            'patch_set' => [[
                'path' => 'app/Http/Controllers/InvoiceController.php',
                'type' => 'M',
                'lines' => [[40, 44]],
            ]],
        ]]);

        return [$project, $author];
    }

    private function ingest(Project $project): void
    {
        $eventId = IngestPayload::freshEventId();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode([
                'event_id' => $eventId,
                'timestamp' => Carbon::now()->toIso8601String(),
                'platform' => 'php',
                'release' => '1.0.0',
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
            ]),
        ]);

        ProcessIngestPayload::dispatch($payload);
    }

    public function test_a_new_issue_goes_to_the_author_of_the_suspect_commit(): void
    {
        [$project, $author] = $this->context(autoAssign: true);

        $this->ingest($project);

        $issue = Issue::query()->sole();

        $this->assertSame($author->id, $issue->assigned_user_id);
        // Zugewiesen hat der Abgleich und keine Person — und „zugewiesen heißt
        // geprüft" gilt trotzdem.
        $this->assertNull($issue->assigned_by_id);
        $this->assertNull($issue->for_review_at);
    }

    public function test_without_the_project_switch_nothing_is_assigned(): void
    {
        [$project] = $this->context(autoAssign: false);

        $this->ingest($project);

        $this->assertNull(Issue::query()->sole()->assigned_user_id);
    }

    /**
     * Die zweite Meldung darf nichts mehr anfassen: wer die Zuständigkeit
     * inzwischen abgegeben hat, hat das entschieden.
     */
    public function test_only_the_first_occurrence_assigns(): void
    {
        [$project] = $this->context(autoAssign: true);

        $this->ingest($project);

        Issue::query()->sole()->update(['assigned_user_id' => null, 'assigned_at' => null]);

        $this->ingest($project);

        $this->assertNull(Issue::query()->sole()->assigned_user_id);
    }

    /**
     * Ein Commit von jemandem ohne Konto bleibt in der Anzeige stehen und wird
     * nicht zur Zuständigkeit — die gäbe es dann für eine Adresse, die niemand
     * liest.
     */
    public function test_an_author_without_an_account_is_not_assigned(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create([
            'auto_assign_suspect_commits' => true,
        ]);

        $release = Release::factory()->for($project)->version('1.0.0')->create();

        CommitImport::into($release, [[
            'id' => 'cccc333dddd',
            'repository' => 'acme/webshop',
            'author_email' => 'extern@fremd.test',
            'author_name' => 'Externe Person',
            'patch_set' => [[
                'path' => 'app/Http/Controllers/InvoiceController.php',
                'type' => 'M',
                'lines' => [[40, 44]],
            ]],
        ]]);

        $this->ingest($project);

        $this->assertNull(Issue::query()->sole()->assigned_user_id);
    }
}
