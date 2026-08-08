<?php

namespace Tests\Feature\Releases;

use App\Enums\CommitFileChange;
use App\Models\Commit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
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
}
