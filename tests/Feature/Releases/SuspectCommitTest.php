<?php

namespace Tests\Feature\Releases;

use App\Enums\CommitFileChange;
use App\Enums\OrganizationRole;
use App\Models\Commit;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
use App\Support\Releases\CommitImport;
use App\Support\Releases\SuspectCommits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die verdächtigen Commits (R4): welcher Commit den Fehler verursacht haben
 * könnte — und warum.
 *
 * Der Kern ist der Abgleich über **Datei und Zeile**. Nur über den Dateinamen
 * wäre er beinahe wertlos: dieselbe Datei wird in einer Auslieferung von
 * mehreren Commits angefasst. Die Prüfungen hier drehen sich deshalb um die
 * Frage, ob der Commit, der die Zeile aus dem Stacktrace angefasst hat,
 * tatsächlich vor dem steht, der nur zufällig in derselben Datei war.
 */
class SuspectCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Organization, Project, Release}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);
        $release = Release::factory()->for($project)->version('1.2.0')->create();

        $user->switchOrganization($organization);

        return [$user, $organization, $project, $release];
    }

    /**
     * Ein Fehler, dessen erstes Auftreten in dieser Auslieferung liegt — samt
     * einer Meldung mit Stacktrace.
     *
     * @param  list<array<string, mixed>>  $frames
     */
    private function issueWithFrames(Project $project, Release $release, array $frames): Issue
    {
        $issue = Issue::factory()->for($project)->create([
            'title' => 'RuntimeException: Warenkorb leer',
            'first_release_id' => $release->id,
            'first_release_at' => Carbon::now()->subHour(),
        ]);

        $group = EventGroup::factory()->for($project)->for($issue)->create();

        Event::factory()->for($project)->create([
            'event_group_id' => $group->id,
            'exceptions' => [[
                'type' => 'RuntimeException',
                'value' => 'Warenkorb leer',
                'frames' => $frames,
            ]],
        ]);

        return $issue;
    }

    /**
     * Ein Commit mit einer angefassten Datei, wahlweise mit Zeilenbereichen.
     *
     * @param  list<array{int, int}>|null  $lines
     */
    private function commit(
        Repository $repository,
        Release $release,
        string $sha,
        string $path,
        ?array $lines = null,
        ?User $author = null,
        int $position = 0,
    ): Commit {
        $commit = Commit::factory()->for($repository)->create([
            'sha' => $sha,
            'message' => 'Steuer wird nur einmal addiert',
            'author_name' => $author === null ? 'Alex Autor' : $author->name,
            'author_email' => $author === null ? 'alex@acme.test' : $author->email,
            'author_id' => $author?->id,
            'committed_at' => Carbon::now()->subHours(2),
        ]);

        $commit->files()->create([
            'path' => $path,
            'change_type' => CommitFileChange::Modified,
            'line_ranges' => $lines,
        ]);

        $release->commits()->attach($commit->id, ['position' => $position]);

        return $commit;
    }

    public function test_the_commit_that_touched_the_line_outranks_the_one_that_only_touched_the_file(): void
    {
        [, $organization, $project, $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);

        // Zwei Commits an derselben Datei, aber an verschiedenen Stellen. Ohne
        // den Abgleich über die Zeile wären beide gleich verdächtig — und das
        // ist genau der Fall, für den es R4 gibt.
        $elsewhere = $this->commit($repository, $release, 'bbbb222', 'app/Cart.php', [[900, 950]], position: 0);
        $culprit = $this->commit($repository, $release, 'aaaa111', 'app/Cart.php', [[40, 48]], position: 1);

        $issue = $this->issueWithFrames($project, $release, [[
            'filename' => '/var/www/html/app/Cart.php',
            'function' => 'total',
            'lineno' => 42,
            'in_app' => true,
        ]]);

        $suspects = SuspectCommits::forEvent($issue, Event::query()->sole());

        $this->assertCount(2, $suspects);
        $this->assertSame($culprit->id, $suspects[0]->commit->id);
        $this->assertTrue($suspects[0]->matchedLine);
        $this->assertSame($elsewhere->id, $suspects[1]->commit->id);
        $this->assertFalse($suspects[1]->matchedLine);
    }

    public function test_a_commit_whose_files_are_nowhere_in_the_stack_trace_is_not_suspect(): void
    {
        [, $organization, $project, $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);
        $this->commit($repository, $release, 'cccc333', 'app/Invoice.php', [[10, 20]]);

        $issue = $this->issueWithFrames($project, $release, [[
            'filename' => 'app/Cart.php',
            'lineno' => 42,
            'in_app' => true,
        ]]);

        $this->assertSame([], SuspectCommits::forEvent($issue, Event::query()->sole()));
    }

    /**
     * Der Fall, an dem ein Vergleich von Zeichenketten scheitern würde: im
     * Repository steht ein relativer Pfad, im Stacktrace ein absoluter — und
     * derselbe Dateiname liegt zusätzlich in einer Bibliothek.
     */
    public function test_the_paths_are_compared_from_the_back_and_not_by_file_name_alone(): void
    {
        [, $organization, $project, $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);
        $own = $this->commit($repository, $release, 'dddd444', 'src/checkout/Cart.php', position: 0);
        $this->commit($repository, $release, 'eeee555', 'vendor/acme/lib/Cart.php', position: 1);

        $issue = $this->issueWithFrames($project, $release, [[
            'filename' => 'C:\\build\\app\\src\\checkout\\Cart.php',
            'lineno' => 7,
            'in_app' => true,
        ]]);

        $suspects = SuspectCommits::forEvent($issue, Event::query()->sole());

        // Beide tragen den Dateinamen, aber nur einer teilt den ganzen Weg
        // dahin — und der steht vorn.
        $this->assertSame($own->id, $suspects[0]->commit->id);
    }

    public function test_without_a_connected_repository_the_page_shows_no_suspects(): void
    {
        [$user, , $project, $release] = $this->context();

        $issue = $this->issueWithFrames($project, $release, [[
            'filename' => 'app/Cart.php',
            'lineno' => 42,
            'in_app' => true,
        ]]);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Show')
                ->where('suspects', []));
    }

    public function test_the_detail_page_names_the_suspect_with_its_reason(): void
    {
        [$user, $organization, $project, $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create([
            'name' => 'acme/webshop',
            'url' => 'https://github.com/acme/webshop',
        ]);
        $this->commit($repository, $release, 'aaaa111bbbb', 'app/Cart.php', [[40, 48]]);

        $issue = $this->issueWithFrames($project, $release, [[
            'filename' => 'app/Cart.php',
            'lineno' => 42,
            'in_app' => true,
        ]]);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Show')
                ->has('suspects', 1)
                ->where('suspects.0.shortSha', 'aaaa111')
                ->where('suspects.0.reason.path', 'app/Cart.php')
                ->where('suspects.0.reason.line', 42)
                ->where('suspects.0.reason.matchedLine', true)
                ->where('suspects.0.author.name', 'Alex Autor'));
    }

    public function test_the_author_leads_the_assignment_suggestions_when_the_issue_is_known(): void
    {
        [$user, $organization, $project, $release] = $this->context();

        $author = User::factory()->create(['name' => 'Bea Beitragende', 'email' => 'bea@acme.test']);
        $organization->setRole($author, OrganizationRole::Member);

        $repository = Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);
        $this->commit($repository, $release, 'ffff666', 'app/Cart.php', [[40, 48]], author: $author);

        $issue = $this->issueWithFrames($project, $release, [[
            'filename' => 'app/Cart.php',
            'lineno' => 42,
            'in_app' => true,
        ]]);

        $response = $this->actingAs($user)
            ->getJson(route('issues.assignment.suggest', ['issue' => $issue->id]));

        $response->assertOk()
            ->assertJsonPath('suggestions.0.value', 'bea@acme.test')
            ->assertJsonPath('suggestions.0.kind', 'suspect');

        // Und ohne die Kennung des Fehlers bleibt es bei der Mitgliederliste:
        // eine Sammelaktion hat keinen Stacktrace, gegen den sich etwas
        // abgleichen ließe.
        $this->actingAs($user)
            ->getJson(route('issues.assignment.suggest'))
            ->assertOk()
            ->assertJsonPath('suggestions.0.kind', 'self');
    }

    /**
     * Die Zeilenbereiche kommen bei einer Anbindung als Unterschied im üblichen
     * Format an — und nicht als fertige Liste.
     */
    public function test_the_line_ranges_are_read_from_a_unified_diff(): void
    {
        [, , , $release] = $this->context();

        CommitImport::into($release, [[
            'id' => 'abc1234',
            'repository' => 'acme/webshop',
            'patch_set' => [[
                'path' => 'app/Cart.php',
                'type' => 'M',
                'patch' => "@@ -38,6 +38,7 @@ class Cart\n context\n context\n-  weg\n+  neu\n+  auch neu\n context\n",
            ]],
        ]]);

        $file = Commit::query()->sole()->files()->sole();

        // Der Kopf des Blocks beginnt bei 38, zwei behaltene Zeilen später
        // stehen die Änderungen — die drei Zeilen Umgebung davor und danach
        // gehören ausdrücklich nicht dazu.
        $this->assertSame([[40, 41]], $file->line_ranges);
        $this->assertTrue($file->touchesLine(41));
        $this->assertFalse($file->touchesLine(38));
    }

    /**
     * Ohne Angabe bleibt es bei `null` — „nicht bekannt" ist etwas anderes als
     * „keine Zeile geändert", und der Abgleich behandelt beides verschieden.
     */
    public function test_a_patch_set_without_lines_keeps_the_ranges_unknown(): void
    {
        [, , , $release] = $this->context();

        CommitImport::into($release, [[
            'id' => 'def5678',
            'repository' => 'acme/webshop',
            'patch_set' => [['path' => 'app/Cart.php', 'type' => 'M']],
        ]]);

        $file = Commit::query()->sole()->files()->sole();

        $this->assertNull($file->line_ranges);
        $this->assertFalse($file->hasLineRanges());
    }
}
