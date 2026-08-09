<?php

namespace Tests\Feature\Releases;

use App\Enums\CommitFileChange;
use App\Enums\IssueStatus;
use App\Enums\ReleaseArtifactKind;
use App\Http\Requests\IssueListRequest;
use App\Models\Commit;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use App\Models\ReleaseSessionCount;
use App\Models\Repository;
use App\Models\User;
use App\Support\Releases\Health\SessionTally;
use App\Support\Releases\ReleaseDetail;
use App\Support\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Detailseite einer Auslieferung: was in ihr steckt, und wer sie sehen darf.
 *
 * Anders als die Liste hat die Seite keine Vorauswahl über die Filterleiste —
 * sie wird über eine Kennung in der Adresszeile aufgerufen, und eine geratene
 * Kennung ist ein Aufruf wie jeder andere.
 */
class ReleaseDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project, Release}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);
        // Die Zeitpunkte ausdrücklich und nicht aus der Fabrik: die Liste
        // filtert über den gewählten Zeitraum, und eine gewürfelte Spanne von
        // bis zu dreißig Tagen fiele mal hinein und mal heraus.
        $release = Release::factory()->for($project)->version('1.2.0')->create([
            'first_event_at' => Carbon::now()->subHours(2),
            'last_event_at' => Carbon::now()->subHour(),
            'released_at' => Carbon::now()->subHours(3),
        ]);

        $user->switchOrganization($organization);

        return [$user, $organization, $project, $release];
    }

    private function commit(Repository $repository, Release $release, string $sha, int $position = 0): Commit
    {
        $commit = Commit::factory()->for($repository)->create([
            'sha' => $sha,
            'message' => "Warenkorb rechnet richtig\n\nDie Steuer wurde zweimal addiert.",
            'author_name' => 'Alex Autor',
            'author_email' => 'alex@acme.test',
        ]);

        $commit->files()->create([
            'path' => 'app/Cart.php',
            'change_type' => CommitFileChange::Modified,
        ]);

        $release->commits()->attach($commit->id, ['position' => $position]);

        return $commit;
    }

    public function test_the_page_shows_the_commits_with_author_message_and_files(): void
    {
        [$user, $organization, , $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);
        $this->commit($repository, $release, 'aaaa111aaaa');

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('releases/Show')
                ->where('release.version', '1.2.0')
                ->has('commits', 1)
                ->where('commits.0.shortSha', 'aaaa111')
                ->where('commits.0.title', 'Warenkorb rechnet richtig')
                ->where('commits.0.body', 'Die Steuer wurde zweimal addiert.')
                ->where('commits.0.author.name', 'Alex Autor')
                // Ohne Konto: der Name stammt aus dem Repository, und die Seite
                // sagt das auch.
                ->where('commits.0.author.isMember', false)
                ->has('commits.0.files', 1)
                ->where('commits.0.files.0.path', 'app/Cart.php')
                ->where('commits.0.files.0.change', 'M')
                // Die Adresse des Repositories macht aus dem Hash einen Link.
                ->where('commits.0.href', $repository->url.'/commit/aaaa111aaaa')
            );
    }

    /**
     * Die Reihenfolge der Übergabe zählt und nicht die Zeit im Repository —
     * nach einem Rebase gibt die Zeit die Stellung verkehrt herum wieder.
     */
    public function test_commits_appear_in_the_order_they_were_handed_over_in(): void
    {
        [$user, $organization, , $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create();

        $this->commit($repository, $release, 'bbbb222bbbb', 0)
            ->forceFill(['committed_at' => now()->subDay()])->save();
        $this->commit($repository, $release, 'aaaa111aaaa', 1)
            ->forceFill(['committed_at' => now()->subDays(3)])->save();

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commits.0.sha', 'bbbb222bbbb')
                ->where('commits.1.sha', 'aaaa111aaaa')
            );
    }

    /**
     * Der Name aus dem Konto geht vor: er ist der, unter dem die Person hier
     * auftritt, während die Angabe im Commit aus der Git-Einstellung eines
     * Rechners stammt.
     */
    public function test_a_matched_author_is_shown_with_the_name_from_the_account(): void
    {
        [$user, $organization, , $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create();
        $commit = $this->commit($repository, $release, 'aaaa111aaaa');
        $commit->forceFill(['author_id' => $user->id])->save();

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commits.0.author.name', $user->name)
                ->where('commits.0.author.isMember', true)
            );
    }

    /**
     * Eine gekürzte Liste sähe aus wie die ganze — und wer einen bestimmten
     * Commit sucht, hielte ihn für nicht ausgeliefert.
     */
    public function test_a_very_long_commit_list_is_cut_off_and_says_so(): void
    {
        [$user, $organization, , $release] = $this->context();

        $repository = Repository::factory()->for($organization)->create();

        $commits = Commit::factory()
            ->count(ReleaseDetail::MAX_COMMITS + 2)
            ->for($repository)
            ->create();

        foreach ($commits as $position => $commit) {
            $release->commits()->attach($commit->id, ['position' => $position]);
        }

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('commits', ReleaseDetail::MAX_COMMITS)
                ->where('commitsTruncated', true)
                // Die volle Zahl, nicht die der gezeigten Zeilen.
                ->where('commitsLabel', (string) (ReleaseDetail::MAX_COMMITS + 2))
            );
    }

    /**
     * Eine Version, die nur aus Meldungen entstanden ist, hat keine Commits —
     * und das ist kein Fehler, sondern der Regelfall ohne Anbindung.
     */
    public function test_a_release_without_commits_shows_an_empty_page(): void
    {
        [$user, , , $release] = $this->context();

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('commits', 0));
    }

    /**
     * Auf der Seite stehen Commit-Nachrichten und Namen von Personen. Ohne die
     * Prüfung wäre sie der Weg von einer geratenen Kennung in die Arbeit eines
     * fremden Teams.
     */
    public function test_someone_outside_the_organization_cannot_open_the_page(): void
    {
        [, , , $release] = $this->context();

        $outsider = User::factory()->create();
        Organization::factory()->withMember($outsider)->create();

        $this->actingAs($outsider)
            ->get(route('releases.show', $release))
            ->assertForbidden();
    }

    /**
     * Der Link von der Liste auf die Detailseite — die Frage „was steckt drin?"
     * stellt man dort.
     */
    public function test_the_list_links_to_the_detail_page(): void
    {
        [$user, , , $release] = $this->context();

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('releases.data.0.href', route('releases.show', $release))
            );
    }

    /**
     * Der eigentliche Zweck der Zahlen: „99,2 % absturzfrei" allein sagt
     * niemandem, ob die Auslieferung gut war.
     */
    public function test_the_page_compares_the_release_against_the_previous_one(): void
    {
        [$user, , $project, $release] = $this->context();

        $previous = $this->previous($project, '1.1.0');

        // Vorher 90 % absturzfrei, jetzt 80 % — zehn Punkte schlechter.
        $this->sessions($previous, 100, 10);
        $this->sessions($release, 100, 20);

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('releases/Show')
                ->where('health.crashFreeSessions.value', 80.0)
                ->where('comparison.version', '1.1.0')
                ->where('comparison.crashFreeSessions.value', -10.0)
                ->where('comparison.crashFreeSessions.direction', 'down')
                ->where('previousHref', route('releases.show', $previous))
            );
    }

    /**
     * Ohne Vorversion gibt es keinen Vergleich — und dann steht dort `null` und
     * nicht eine Null. Der Unterschied ist der zwischen „nichts verändert" und
     * „nichts zu vergleichen".
     */
    public function test_the_first_release_of_a_project_has_nothing_to_compare_against(): void
    {
        [$user, , , $release] = $this->context();

        $this->sessions($release, 10, 0);

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('comparison', null)
                ->where('previousHref', null)
            );
    }

    /**
     * Eine Version ohne Sitzungen ist nicht gesund, sondern unbekannt.
     */
    public function test_a_release_without_sessions_shows_no_rate(): void
    {
        [$user, , , $release] = $this->context();

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('health.hasData', false)
                ->where('health.crashFreeSessions', null)
                ->where('adoption.hasData', false)
            );
    }

    /**
     * Drei Zahlen, drei Aussagen — und jede führt in die Fehlerliste, gefiltert
     * auf genau diese Menge.
     */
    public function test_the_page_counts_new_resolved_and_regressed_issues_and_links_them(): void
    {
        [$user, , $project, $release] = $this->context();

        Issue::factory()->for($project)->create(['first_release_id' => $release->id]);
        Issue::factory()->for($project)->create(['first_release_id' => $release->id]);
        Issue::factory()->for($project)->create(['resolved_in_release_id' => $release->id]);
        Issue::factory()->for($project)->create([
            'regressed_in_release_id' => $release->id,
            'regressed_at' => Carbon::now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.new.count', 2)
                ->where('issues.resolved.count', 1)
                ->where('issues.regressed.count', 1)
                ->where('issues.regressed.href', route('issues.index', [
                    'q' => SearchQuery::term('regressedInRelease', $release->version),
                ]))
            );
    }

    /**
     * Der Link hinter der Zahl muss dieselbe Menge treffen, die die Zahl gezählt
     * hat — sonst zeigt die Fehlerliste etwas anderes als die Übersicht.
     */
    public function test_the_link_behind_the_resolved_count_finds_the_same_issues(): void
    {
        [$user, , $project, $release] = $this->context();

        $resolved = Issue::factory()->for($project)->create([
            'resolved_in_release_id' => $release->id,
            'status' => IssueStatus::Resolved,
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
        ]);

        Issue::factory()->for($project)->create([
            'first_release_id' => $release->id,
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get(route('issues.index', [
                'q' => SearchQuery::term('resolvedInRelease', $release->version),
                // Ohne diese Angabe zeigt die Fehlerliste nur Offenes — und ein
                // erledigter Fehler ist genau das, was hier gesucht wird.
                'status' => IssueListRequest::STATUS_ANY,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.id', $resolved->id)
            );
    }

    /**
     * Die hochgeladenen Artefakte (R5) stehen auf dieser Seite, weil ihr Fehlen
     * sonst erst vor einem unlesbaren Stacktrace auffällt.
     */
    public function test_the_page_lists_the_uploaded_artifacts(): void
    {
        [$user, , $project, $release] = $this->context();

        ReleaseArtifact::query()->create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'name' => '~/static/js/app.js',
            'kind' => ReleaseArtifactKind::Bundle,
            'source_map_ref' => 'app.js.map',
            'size' => 2048,
            'checksum' => str_repeat('a', 40),
            'path' => 'artifacts/app.js',
        ]);

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('artifacts', 1)
                ->where('artifacts.0.name', '~/static/js/app.js')
                ->where('artifacts.0.hasDebugId', false)
                // Der Verweis wird gegen den eigenen Pfad aufgelöst.
                ->where('artifacts.0.sourceMap', '~/static/js/app.js.map')
                ->where('artifactsTruncated', false)
            );
    }

    /**
     * Der Zeitraum der Filterleiste gilt für die Kennzahlen (F7) — eine
     * Auslieferung, deren Sitzungen außerhalb liegen, hat darin keine Quote.
     */
    public function test_the_period_of_the_filter_bar_applies_to_the_numbers(): void
    {
        [$user, , , $release] = $this->context();

        $this->sessions($release, 10, 5, Carbon::now()->subDays(20));

        $this->actingAs($user)
            ->get(route('releases.show', [$release, 'period' => '24h']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('health.hasData', false)
            );

        $this->actingAs($user)
            ->get(route('releases.show', [$release, 'period' => '30d']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('health.crashFreeSessions.value', 50.0)
            );
    }

    /**
     * Die Projektauswahl steht auf dieser Seite nicht zur Wahl: welches Projekt
     * gemeint ist, sagt die Version.
     */
    public function test_the_filter_bar_offers_no_project_choice(): void
    {
        [$user, , , $release] = $this->context();

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.showProjects', false)
                ->has('filter.projectOptions', 0)
            );
    }

    /**
     * Eine Vorversion desselben Projekts — mit Zeitpunkten, die im
     * Standard-Zeitraum liegen.
     */
    private function previous(Project $project, string $version): Release
    {
        return Release::factory()->for($project)->version($version)->create([
            'first_event_at' => Carbon::now()->subHours(6),
            'last_event_at' => Carbon::now()->subHours(4),
            'released_at' => Carbon::now()->subHours(7),
        ]);
    }

    /**
     * Zählt Sitzungen auf eine Version — über denselben Weg, den die Aufnahme
     * benutzt (R7).
     */
    private function sessions(Release $release, int $sessions, int $crashed, ?Carbon $at = null): void
    {
        ReleaseSessionCount::apply([
            'project_id' => $release->project_id,
            'release_id' => $release->id,
            'environment' => 'production',
            'bucket_start' => ReleaseSessionCount::bucket($at ?? Carbon::now()->subMinutes(5)),
        ], new SessionTally(sessions: $sessions, crashed: $crashed));
    }
}
