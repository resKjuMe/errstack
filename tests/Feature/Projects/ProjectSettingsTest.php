<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Enums\ResolutionBehavior;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project}
     */
    private function project(OrganizationRole $role = OrganizationRole::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        return [$user, $organization, $project];
    }

    public function test_the_settings_survive_a_reload(): void
    {
        [$user, $organization, $project] = $this->project();
        $path = "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}";

        $this->actingAs($user)->patch($path, [
            'name' => 'Webshop',
            'platform' => 'javascript',
            'default_environment' => 'staging',
            'resolution_behavior' => 'after_week',
            'retention_days' => 90,
            'attachment_retention_days' => 14,
        ])->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertSame(Platform::JavaScript, $project->platform);
        $this->assertSame('staging', $project->default_environment);
        $this->assertSame(ResolutionBehavior::AfterWeek, $project->resolution_behavior);
        $this->assertSame(90, $project->retention_days);
        $this->assertSame(14, $project->attachment_retention_days);

        $this->actingAs($user)->get($path)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project.defaultEnvironment', 'staging')
                ->where('project.resolutionBehavior', 'after_week')
                ->where('project.retentionDays', 90)
                ->where('project.attachmentRetentionDays', 14)
            );
    }

    public function test_renaming_keeps_the_slug_so_links_stay_valid(): void
    {
        [$user, $organization, $project] = $this->project();

        $this->actingAs($user)->patch("/einstellungen/organisationen/{$organization->slug}/projekte/webshop", [
            'name' => 'Ganz neu',
            'platform' => $project->platform->value,
            'default_environment' => 'production',
            'resolution_behavior' => 'manual',
            'retention_days' => 30,
            'attachment_retention_days' => 7,
        ])->assertSessionHasNoErrors();

        $this->assertSame('webshop', $project->refresh()->slug);
        $this->assertSame('Ganz neu', $project->name);
    }

    public function test_the_settings_are_checked(): void
    {
        [$user, $organization] = $this->project();
        $path = "/einstellungen/organisationen/{$organization->slug}/projekte/webshop";

        $this->actingAs($user)->patch($path, [
            'name' => 'Webshop',
            'platform' => 'php',
            // Grossbuchstaben und Leerzeichen taugen nicht als Umgebung.
            'default_environment' => 'Produktion live',
            'resolution_behavior' => 'irgendwann',
            'retention_days' => 0,
            'attachment_retention_days' => 400,
        ])->assertSessionHasErrors([
            'default_environment',
            'resolution_behavior',
            'retention_days',
            'attachment_retention_days',
        ]);
    }

    public function test_members_may_read_the_settings_but_not_change_them(): void
    {
        [$member, $organization, $project] = $this->project(OrganizationRole::Member);
        $path = "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}";

        $this->actingAs($member)->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('permissions.update', false)
                // Die DSN ist ein Zugangsschlüssel — wer sie nicht verwalten
                // darf, bekommt nicht einmal den Weg dorthin zu sehen.
                ->where('project.keysHref', null)
            );

        $this->actingAs($member)->patch($path, [
            'name' => 'Fremd',
            'platform' => 'php',
            'default_environment' => 'production',
            'resolution_behavior' => 'manual',
            'retention_days' => 30,
            'attachment_retention_days' => 7,
        ])->assertForbidden();

        $this->assertSame('Webshop', $project->refresh()->name);
    }

    public function test_the_settings_page_points_to_the_client_keys(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)
            ->get("/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('permissions.manageKeys', true)
                ->where('project.keysHref', route('projects.keys.index', [$organization, $project]))
            );
    }

    public function test_teams_of_the_organization_can_be_assigned_and_removed(): void
    {
        [$owner, $organization, $project] = $this->project();
        $team = Team::factory()->for($organization)->create();
        $path = "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/teams";

        $this->actingAs($owner)->put($path, ['teams' => [$team->id]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('project_team', ['project_id' => $project->id, 'team_id' => $team->id]);

        $this->actingAs($owner)->put($path, ['teams' => []])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('project_team', 0);
    }

    public function test_a_team_of_another_organization_cannot_be_assigned(): void
    {
        [$owner, $organization, $project] = $this->project();
        $foreign = Team::factory()->create();

        $this->actingAs($owner)
            ->put("/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/teams", [
                'teams' => [$foreign->id],
            ])
            ->assertSessionHasErrors('teams.0');

        $this->assertDatabaseCount('project_team', 0);
    }

    public function test_the_settings_page_offers_all_teams_of_the_organization(): void
    {
        [$owner, $organization, $project] = $this->project();
        $assigned = Team::factory()->for($organization)->create(['name' => 'Alpha']);
        Team::factory()->for($organization)->create(['name' => 'Beta']);
        Team::factory()->create();
        $project->teams()->attach($assigned);

        $this->actingAs($owner)
            ->get("/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('teams', 2)
                ->where('teams.0.name', 'Alpha')
                ->where('teams.0.assigned', true)
                ->where('teams.1.assigned', false)
                ->where('permissions.manageTeams', true)
            );
    }
}
