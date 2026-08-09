<?php

namespace Tests\Feature\Overviews;

use App\Enums\OrganizationRole;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Team-Sicht.
 *
 * Ihr Eigenes ist die Grenze: Projekte des Teams, ungeprüfte Fehler darin,
 * Zuweisungen an das Team und seine Mitglieder. Eine Team-Seite, die fremde
 * Zahlen zeigt, ist keine Team-Seite.
 */
class TeamOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-07 12:00:00';

    private const INSIDE = '2026-08-07 11:00:00';

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
     * @return array{User, Organization, Team, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $team = Team::factory()->for($organization)->create(['name' => 'Zahlung']);
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $team->projects()->attach($project);
        $user->switchOrganization($organization);

        return [$user, $organization, $team, $project];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function panel(User $user, Organization $organization, Team $team, string $panel, array $query = []): array
    {
        return $this->actingAs($user)
            ->getJson(route('teams.overview.panel', [$organization, $team, 'panel' => $panel] + $query + ['tz' => 'UTC']))
            ->assertOk()
            ->json('panel');
    }

    public function test_the_page_delivers_one_address_per_panel(): void
    {
        [$user, $organization, $team] = $this->context();

        $this->actingAs($user)
            ->get(route('teams.overview', [$organization, $team]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('teams/Overview')
                ->has('panels', 3)
                ->where('team.name', 'Zahlung')
            );
    }

    /**
     * Fremde Projekte bleiben draußen, auch wenn die Filterleiste sie wählt.
     */
    public function test_projects_outside_the_team_stay_out(): void
    {
        [$user, $organization, $team] = $this->context();
        Project::factory()->for($organization)->create(['name' => 'Fremd', 'slug' => 'fremd']);

        $panel = $this->panel($user, $organization, $team, 'projects');

        $this->assertSame(['Webshop'], array_column($panel['rows'], 'title'));
    }

    /**
     * Die Arbeitsliste sind die ungeprüften Fehler — alles andere gehört nicht
     * hierher.
     */
    public function test_the_review_panel_lists_only_unreviewed_issues(): void
    {
        [$user, $organization, $team, $project] = $this->context();

        Issue::factory()->for($project)->create([
            'title' => 'Ungeprüft',
            'first_seen' => self::INSIDE,
            'last_seen' => self::INSIDE,
            'for_review_at' => self::INSIDE,
        ]);
        Issue::factory()->for($project)->create([
            'title' => 'Gesehen',
            'first_seen' => self::INSIDE,
            'last_seen' => self::INSIDE,
            'for_review_at' => null,
        ]);

        $panel = $this->panel($user, $organization, $team, 'review', ['period' => '24h']);

        $this->assertSame(['Ungeprüft'], array_column($panel['rows'], 'title'));
    }

    /**
     * Zugewiesen ist zugewiesen: dem Team selbst oder einem seiner Mitglieder.
     * Wer wissen will, was auf sein Team wartet, macht diesen Unterschied
     * nicht.
     */
    public function test_assignments_cover_the_team_and_its_members(): void
    {
        [$user, $organization, $team, $project] = $this->context();
        $team->members()->attach($user);

        $stranger = User::factory()->create();
        $organization->setRole($stranger, OrganizationRole::Member);

        Issue::factory()->for($project)->create([
            'title' => 'An das Team',
            'first_seen' => self::INSIDE,
            'last_seen' => self::INSIDE,
            'assigned_team_id' => $team->id,
            'assigned_at' => self::INSIDE,
        ]);
        Issue::factory()->for($project)->create([
            'title' => 'An ein Mitglied',
            'first_seen' => self::INSIDE,
            'last_seen' => self::INSIDE,
            'assigned_user_id' => $user->id,
            'assigned_at' => self::INSIDE,
        ]);
        Issue::factory()->for($project)->create([
            'title' => 'An jemand anderen',
            'first_seen' => self::INSIDE,
            'last_seen' => self::INSIDE,
            'assigned_user_id' => $stranger->id,
            'assigned_at' => self::INSIDE,
        ]);

        $panel = $this->panel($user, $organization, $team, 'assignments', ['period' => '24h']);

        $titles = array_column($panel['rows'], 'title');

        sort($titles);

        $this->assertSame(['An das Team', 'An ein Mitglied'], $titles);
    }

    /**
     * Ohne Projekte gibt es keinen Weg in die Fehlerliste: eine Adresse ohne
     * Projektauswahl bedeutet dort „alle Projekte der Organisation" — genau
     * das, was eine Team-Seite nicht zeigen darf.
     */
    public function test_without_projects_the_panel_offers_no_link(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $team = Team::factory()->for($organization)->create();
        Project::factory()->for($organization)->create(['slug' => 'fremd']);
        $user->switchOrganization($organization);

        $panel = $this->panel($user, $organization, $team, 'review');

        $this->assertNull($panel['href']);
    }

    /**
     * Wer der Organisation nicht angehört, sieht die Team-Sicht nicht.
     */
    public function test_a_stranger_cannot_read_the_overview(): void
    {
        [, $organization, $team] = $this->context();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('teams.overview', [$organization, $team]))
            ->assertForbidden();
    }
}
