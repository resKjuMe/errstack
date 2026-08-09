<?php

namespace Tests\Feature\Overviews;

use App\Models\Event;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\OwnershipRule;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Übersicht eines Projekts.
 *
 * Geprüft wird, was diese Seite ausmacht: dass sie an ihrem Projekt hängt und
 * nicht an der Projektauswahl der Leiste, dass ihre Listen den Zeitraum
 * beachten, und dass jede Zeile weiterführt.
 */
class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-07 12:00:00';

    private const INSIDE = '2026-08-07 11:00:00';

    /** Außerhalb der letzten 24 Stunden, innerhalb der letzten 7 Tage. */
    private const LAST_WEEK = '2026-08-04 11:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        CarbonImmutable::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
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

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function panel(User $user, Organization $organization, Project $project, string $panel, array $query = []): array
    {
        return $this->actingAs($user)
            ->getJson(route('projects.overview.panel', [$organization, $project, 'panel' => $panel] + $query + ['tz' => 'UTC']))
            ->assertOk()
            ->json('panel');
    }

    public function test_the_page_delivers_one_address_per_panel(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->get(route('projects.overview', [$organization, $project]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Overview')
                ->has('panels', 4)
                ->where('project.slug', 'webshop')
            );
    }

    /**
     * Die Seite zeigt **ihr** Projekt, auch wenn die Filterleiste auf ein
     * anderes zeigt. Eine Seite, die „Webshop" heißt und Zahlen der API zeigt,
     * wäre die schlimmste der möglichen Verwechslungen.
     */
    public function test_the_page_stays_with_its_own_project(): void
    {
        [$user, $organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create(['slug' => 'api']);

        Event::factory()->for($project)->create(['occurred_at' => self::INSIDE, 'received_at' => self::INSIDE]);
        Event::factory()->for($other)->count(3)->create(['occurred_at' => self::INSIDE, 'received_at' => self::INSIDE]);

        $panel = $this->panel($user, $organization, $project, 'errors', [
            'period' => '24h',
            'projects' => [$other->slug],
        ]);

        $this->assertSame(1.0, $panel['total']);
    }

    /**
     * Die Liste der neuesten Fehler beachtet den Zeitraum — und zwar über die
     * Überschneidung: ein Fehler, den es letzte Woche gab und heute wieder
     * gibt, gehört in beide Zeiträume.
     */
    public function test_the_issue_list_respects_the_period(): void
    {
        [$user, $organization, $project] = $this->context();

        Issue::factory()->for($project)->create([
            'title' => 'Frisch',
            'first_seen' => self::INSIDE,
            'last_seen' => self::INSIDE,
        ]);
        Issue::factory()->for($project)->create([
            'title' => 'Alt',
            'first_seen' => self::LAST_WEEK,
            'last_seen' => self::LAST_WEEK,
        ]);

        $day = $this->panel($user, $organization, $project, 'issues', ['period' => '24h']);
        $week = $this->panel($user, $organization, $project, 'issues', ['period' => '7d']);

        $this->assertSame(['Frisch'], array_column($day['rows'], 'title'));
        $this->assertSame(['Frisch', 'Alt'], array_column($week['rows'], 'title'));
    }

    /**
     * Jede Zeile führt zu dem Fehler, über den sie spricht.
     */
    public function test_every_issue_row_links_to_its_issue(): void
    {
        [$user, $organization, $project] = $this->context();

        $issue = Issue::factory()->for($project)->create([
            'first_seen' => self::INSIDE,
            'last_seen' => self::INSIDE,
        ]);

        $panel = $this->panel($user, $organization, $project, 'issues', ['period' => '24h']);

        $this->assertSame(route('issues.show', [$organization, $issue]), $panel['rows'][0]['href']);
    }

    /**
     * Die Zuständigkeiten nennen die Teams und zählen die aktiven Regeln — sie
     * hängen nicht am Zeitraum.
     */
    public function test_ownership_lists_teams_and_counts_active_rules(): void
    {
        [$user, $organization, $project] = $this->context();

        $team = Team::factory()->for($organization)->create(['name' => 'Zahlung']);
        $project->teams()->attach($team);

        OwnershipRule::factory()->for($project)->create(['is_active' => true]);
        OwnershipRule::factory()->for($project)->create(['is_active' => false]);

        $panel = $this->panel($user, $organization, $project, 'ownership');

        $this->assertSame(['Zahlung'], array_column($panel['rows'], 'title'));
        $this->assertSame(1.0, $panel['stats'][0]['value']);
        $this->assertSame(1.0, $panel['stats'][1]['value']);
        $this->assertSame(route('teams.overview', [$organization, $team]), $panel['rows'][0]['href']);
    }

    /**
     * Wer das Projekt nicht sehen darf, sieht auch seine Übersicht nicht.
     */
    public function test_a_stranger_cannot_read_the_overview(): void
    {
        [, $organization, $project] = $this->context();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('projects.overview', [$organization, $project]))
            ->assertForbidden();
    }
}
