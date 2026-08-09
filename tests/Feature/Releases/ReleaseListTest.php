<?php

namespace Tests\Feature\Releases;

use App\Enums\IssueStatus;
use App\Models\Environment;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseSessionCount;
use App\Models\User;
use App\Support\Releases\Health\SessionTally;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Versionsliste: was drinsteht, in welcher Reihenfolge und wer sie sehen
 * darf.
 */
class ReleaseListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    private function release(Project $project, string $version, ?Carbon $seenAt = null): Release
    {
        $seenAt ??= Carbon::now()->subHours(2);

        return Release::factory()->for($project)->version($version)->create([
            'first_event_at' => $seenAt,
            'last_event_at' => $seenAt,
            'released_at' => $seenAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Project $project, array $attributes = []): Issue
    {
        return Issue::factory()->for($project)->create([
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
            ...$attributes,
        ]);
    }

    public function test_the_list_counts_new_and_resolved_issues_per_version(): void
    {
        [$user, , $project] = $this->context();

        $one = $this->release($project, '1.0.0');
        $two = $this->release($project, '1.1.0');

        $this->issue($project, ['first_release_id' => $one->id, 'status' => IssueStatus::Unresolved]);
        $this->issue($project, ['first_release_id' => $two->id, 'status' => IssueStatus::Unresolved]);
        $this->issue($project, ['first_release_id' => $two->id, 'status' => IssueStatus::Resolved]);

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('releases/Index')
                ->has('releases.data', 2)
                // Neueste zuerst.
                ->where('releases.data.0.version', '1.1.0')
                ->where('releases.data.0.newIssues', 2)
                ->where('releases.data.0.resolvedIssues', 1)
                ->where('releases.data.1.version', '1.0.0')
                ->where('releases.data.1.newIssues', 1)
                ->where('releases.data.1.resolvedIssues', 0)
            );
    }

    /**
     * Als Text sortiert stünde `1.10.0` vor `1.9.0` — genau der Fehler, den die
     * zerlegten Sortierfelder verhindern.
     */
    public function test_versions_are_ordered_semantically_and_not_alphabetically(): void
    {
        [$user, , $project] = $this->context();

        $this->release($project, '1.9.0');
        $this->release($project, '1.10.0');
        $this->release($project, '1.10.0-rc.1');

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('releases.data.0.version', '1.10.0')
                // Die Vorabversion steht **hinter** ihrer endgültigen Fassung.
                ->where('releases.data.1.version', '1.10.0-rc.1')
                ->where('releases.data.2.version', '1.9.0')
            );
    }

    /**
     * Eine Angabe ohne Rangfolge steht hinter den nummerierten und sagt das
     * auch — sonst sähe die Sortierung kaputt aus.
     */
    public function test_a_version_without_an_order_is_listed_last_and_marked(): void
    {
        [$user, , $project] = $this->context();

        $this->release($project, 'a1b2c3d4');
        $this->release($project, '1.0.0');

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('releases.data.0.version', '1.0.0')
                ->where('releases.data.0.isOrdered', true)
                ->where('releases.data.1.version', 'a1b2c3d4')
                ->where('releases.data.1.isOrdered', false)
            );
    }

    /**
     * Eine angekündigte Version hat noch keine Spanne — und wäre ausgerechnet
     * am Tag der Auslieferung nicht in der Liste, wenn nur die Überschneidung
     * zählte.
     */
    public function test_an_announced_version_without_events_is_listed(): void
    {
        [$user, , $project] = $this->context();

        Release::factory()->for($project)->version('2.0.0')->announced()->create([
            'released_at' => Carbon::now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('releases.data', 1)
                ->where('releases.data.0.version', '2.0.0')
                ->where('releases.data.0.firstEventAt', null)
                ->where('releases.data.0.newIssues', 0)
            );
    }

    public function test_versions_outside_the_period_are_not_listed(): void
    {
        [$user, , $project] = $this->context();

        $this->release($project, '0.1.0', Carbon::now()->subDays(60));
        $this->release($project, '1.0.0');

        $this->actingAs($user)
            ->get(route('releases.index', ['period' => '24h']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('releases.data', 1)
                ->where('releases.data.0.version', '1.0.0')
            );
    }

    /**
     * Was der Betrachter nicht sehen darf, steht gar nicht erst in der Auswahl —
     * die Rechteprüfung steckt im Filter und nicht in einer Middleware.
     */
    public function test_versions_of_a_foreign_organization_stay_invisible(): void
    {
        [$user, , $project] = $this->context();

        $foreign = Project::factory()->create();

        $this->release($project, '1.0.0');
        $this->release($foreign, '7.7.7');

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('releases.data', 1)
                ->where('releases.data.0.version', '1.0.0')
            );
    }

    public function test_the_page_requires_a_login(): void
    {
        [, $organization] = $this->context();

        $this->get(route('releases.index', $organization))->assertRedirect(route('login'));
    }

    /**
     * Die Zahlen aus den Sitzungsdaten (R7) stehen in der Liste — und zwar
     * gerechnet und nicht abgeschrieben: 8 von 10 Sitzungen heil sind 80 %.
     */
    public function test_the_list_shows_the_crash_free_rate_and_the_adoption_per_version(): void
    {
        [$user, , $project] = $this->context();

        $one = $this->release($project, '1.0.0');
        $two = $this->release($project, '1.1.0');

        $this->sessions($one, 10, 2);
        $this->sessions($two, 30, 0);

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('releases.data.0.version', '1.1.0')
                ->where('releases.data.0.health.crashFreeSessions.value', 100.0)
                // 30 von 40 Sitzungen des Projekts.
                ->where('releases.data.0.health.adoptionSessions.value', 75.0)
                ->where('releases.data.1.version', '1.0.0')
                ->where('releases.data.1.health.crashFreeSessions.value', 80.0)
                ->where('releases.data.1.health.adoptionSessions.value', 25.0)
            );
    }

    /**
     * Die Zusage, an der die ganze Anzeige hängt: aus keiner einzigen Sitzung
     * folgt **keine** Quote — und schon gar nicht „100 %".
     */
    public function test_a_version_without_sessions_has_no_rate_instead_of_a_hundred_percent(): void
    {
        [$user, , $project] = $this->context();

        $this->release($project, '1.0.0');

        $this->actingAs($user)
            ->get(route('releases.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('releases.data.0.health.hasData', false)
                ->where('releases.data.0.health.crashFreeSessions', null)
                ->where('releases.data.0.health.adoptionSessions', null)
            );
    }

    /**
     * Die Frage, wegen der es die Sortierung überhaupt gibt: **welche
     * Auslieferung ist die schlechteste?**
     */
    public function test_sorting_by_health_puts_the_worst_version_first(): void
    {
        [$user, , $project] = $this->context();

        $good = $this->release($project, '2.0.0');
        $bad = $this->release($project, '1.0.0');

        $this->sessions($good, 100, 1);
        $this->sessions($bad, 100, 40);

        $this->actingAs($user)
            ->get(route('releases.index', ['sort' => 'crash_free']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sort', 'crash_free')
                // Ohne die Sortierung stünde 2.0.0 oben — es ist die neuere.
                ->where('releases.data.0.version', '1.0.0')
                ->where('releases.data.1.version', '2.0.0')
            );
    }

    /**
     * Eine Version ohne Sitzungen ist nicht schlecht, sondern unbekannt — und
     * steht damit nicht an der Spitze einer Liste der schlechtesten.
     */
    public function test_sorting_by_health_puts_versions_without_sessions_last(): void
    {
        [$user, , $project] = $this->context();

        $this->release($project, '3.0.0');
        $bad = $this->release($project, '1.0.0');

        $this->sessions($bad, 10, 5);

        $this->actingAs($user)
            ->get(route('releases.index', ['sort' => 'crash_free']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('releases.data.0.version', '1.0.0')
                ->where('releases.data.1.version', '3.0.0')
            );
    }

    public function test_sorting_the_other_way_round_leaves_unordered_versions_at_the_end(): void
    {
        [$user, , $project] = $this->context();

        $this->release($project, '1.0.0');
        $this->release($project, '2.0.0');
        $this->release($project, 'a1b2c3d');

        $this->actingAs($user)
            ->get(route('releases.index', ['sort' => 'oldest']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('releases.data.0.version', '1.0.0')
                ->where('releases.data.1.version', '2.0.0')
                // „Unzerlegbar" heißt nicht „älter", sondern „nicht
                // einzuordnen" — auch andersherum sortiert.
                ->where('releases.data.2.version', 'a1b2c3d')
            );
    }

    /**
     * Die Umgebung entscheidet nicht, welche Versionen dastehen — auf die
     * Kennzahlen daneben wirkt sie sehr wohl.
     */
    public function test_the_environment_narrows_the_numbers_but_not_the_list(): void
    {
        [$user, , $project] = $this->context();

        $release = $this->release($project, '1.0.0');

        $this->sessions($release, 10, 5, 'staging');
        $this->sessions($release, 10, 0, 'production');

        $this->actingAs($user)
            ->get(route('releases.index', ['environment' => 'production']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('environmentPartial', true)
                ->has('releases.data', 1)
                ->where('releases.data.0.health.crashFreeSessions.value', 100.0)
            );
    }

    /**
     * Zählt Sitzungen auf eine Version — über denselben Weg, den die Aufnahme
     * benutzt (R7), damit der Test nicht an einer eigenen Schreibweise hängt.
     */
    private function sessions(Release $release, int $sessions, int $crashed, string $environment = 'production'): void
    {
        // Die Filterleiste bietet nur Umgebungen an, die es gibt — eine
        // unbekannte wird übergangen, und der Test prüfte dann nichts.
        Environment::forName($release->project, $environment);

        ReleaseSessionCount::apply([
            'project_id' => $release->project_id,
            'release_id' => $release->id,
            'environment' => $environment,
            'bucket_start' => ReleaseSessionCount::bucket(Carbon::now()->subMinutes(5)),
        ], new SessionTally(sessions: $sessions, crashed: $crashed));
    }
}
