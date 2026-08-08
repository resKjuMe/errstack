<?php

namespace Tests\Feature\Releases;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
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
        $this->get(route('releases.index'))->assertRedirect(route('login'));
    }
}
