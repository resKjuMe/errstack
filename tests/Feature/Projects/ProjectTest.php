<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overview_lists_the_projects_of_the_active_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $user->switchOrganization($organization);

        Project::createFor($organization, 'Webshop', Platform::Php);
        Project::createFor(Organization::factory()->withMember($user)->create(), 'Anderswo', Platform::Go);

        $this->actingAs($user)->get('/projekte')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Index')
                ->has('projects', 1)
                ->where('projects.0.name', 'Webshop')
                ->where('projects.0.platform', 'php')
                ->where('projects.0.platformLabel', 'PHP')
                ->where('projects.0.platformShort', 'PHP')
                ->where('organization.slug', $organization->slug)
            );
    }

    public function test_the_overview_stays_usable_without_an_organization(): void
    {
        $this->actingAs(User::factory()->create())->get('/projekte')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Index')
                ->where('organization', null)
                ->has('projects', 0)
                ->where('permissions.create', false)
            );
    }

    public function test_administration_can_create_a_project(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/projekte", [
                'name' => 'Webshop',
                'platform' => 'php',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/organisationen/{$organization->slug}/projekte/webshop");

        $project = Project::query()->where('slug', 'webshop')->firstOrFail();

        $this->assertSame($organization->id, $project->organization_id);
        $this->assertSame(Platform::Php, $project->platform);
        // Standardwerte, damit ein frisches Projekt sofort brauchbar ist.
        $this->assertSame('production', $project->default_environment);
        $this->assertSame(30, $project->retention_days);
        // Ohne Schlüssel wüsste keine Anwendung, wohin sie melden soll.
        $this->assertSame(32, strlen($project->keys()->sole()->public_key));
    }

    public function test_members_may_not_create_a_project(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();

        $this->actingAs($member)
            ->post("/organisationen/{$organization->slug}/projekte", [
                'name' => 'Heimlich',
                'platform' => 'php',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_name_and_platform_are_required_and_the_platform_must_be_known(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();

        $this->actingAs($user)
            ->post("/organisationen/{$organization->slug}/projekte", ['name' => '', 'platform' => ''])
            ->assertSessionHasErrors(['name', 'platform']);

        $this->actingAs($user)
            ->post("/organisationen/{$organization->slug}/projekte", ['name' => 'X', 'platform' => 'cobol'])
            ->assertSessionHasErrors('platform');
    }

    public function test_the_slug_is_unique_within_the_organization_but_may_repeat_elsewhere(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $other = Organization::factory()->withMember($user)->create();

        $this->actingAs($user)->post("/organisationen/{$organization->slug}/projekte", ['name' => 'Doppelt', 'platform' => 'php']);
        $this->actingAs($user)->post("/organisationen/{$organization->slug}/projekte", ['name' => 'Doppelt', 'platform' => 'php']);
        $this->actingAs($user)->post("/organisationen/{$other->slug}/projekte", ['name' => 'Doppelt', 'platform' => 'php']);

        $this->assertSame(
            ['doppelt', 'doppelt-2'],
            $organization->projects()->orderBy('id')->pluck('slug')->all(),
        );
        $this->assertSame(['doppelt'], $other->projects()->pluck('slug')->all());
    }

    public function test_every_member_may_look_at_a_project(): void
    {
        foreach (OrganizationRole::cases() as $role) {
            $user = User::factory()->create();
            $organization = Organization::factory()->withMember($user, $role)->create();
            $project = Project::factory()->for($organization)->create();

            $this->actingAs($user)
                ->get("/organisationen/{$organization->slug}/projekte/{$project->slug}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('projects/Show')
                    ->where('project.name', $project->name)
                );
        }
    }

    public function test_outsiders_do_not_get_to_see_a_project(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/organisationen/{$project->organization->slug}/projekte/{$project->slug}")
            ->assertForbidden();
    }

    public function test_a_project_is_not_reachable_through_a_foreign_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $other = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get("/organisationen/{$other->slug}/projekte/{$project->slug}")
            ->assertNotFound();
    }

    public function test_only_administration_may_delete_a_project(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();
        $project = Project::factory()->for($organization)->create();
        $path = "/organisationen/{$organization->slug}/projekte/{$project->slug}";

        $this->actingAs($member)->delete($path)->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $project->id]);

        $admin = User::factory()->create();
        $organization->setRole($admin, OrganizationRole::Admin);

        $this->actingAs($admin)->delete($path)->assertRedirect('/projekte');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_project_takes_its_team_assignment_with_it(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $team = $organization->teams()->create(['name' => 'Plattform']);
        $project = Project::factory()->for($organization)->create();
        $project->teams()->attach($team);

        $this->actingAs($owner)
            ->delete("/organisationen/{$organization->slug}/projekte/{$project->slug}");

        $this->assertDatabaseCount('project_team', 0);
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_deleting_the_organization_takes_its_projects_with_it(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        Project::factory()->for($organization)->create();

        $this->actingAs($owner)->delete("/organisationen/{$organization->slug}");

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_the_project_pages_stay_closed_for_guests(): void
    {
        $this->get('/projekte')->assertRedirect('/login');
    }
}
