<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EnvironmentTest extends TestCase
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

    public function test_a_reported_environment_is_recorded_once(): void
    {
        [, , $project] = $this->project();

        $first = Environment::record($project, 'staging', Carbon::parse('2026-08-01 10:00'));
        $second = Environment::record($project, 'staging', Carbon::parse('2026-08-03 10:00'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Environment::query()->count());
        $this->assertTrue($second->first_seen_at->equalTo(Carbon::parse('2026-08-01 10:00')));
        $this->assertTrue($second->last_seen_at->equalTo(Carbon::parse('2026-08-03 10:00')));
    }

    public function test_a_late_report_does_not_move_the_last_sighting_backwards(): void
    {
        [, , $project] = $this->project();

        Environment::record($project, 'production', Carbon::parse('2026-08-05 10:00'));
        $environment = Environment::record($project, 'production', Carbon::parse('2026-08-02 10:00'));

        $this->assertTrue($environment->last_seen_at->equalTo(Carbon::parse('2026-08-05 10:00')));
    }

    public function test_a_missing_environment_falls_back_to_the_project_default(): void
    {
        [, , $project] = $this->project();
        $project->update(['default_environment' => 'live']);

        $this->assertSame('live', Environment::record($project, null)->name);
        $this->assertSame('live', Environment::record($project, '   ')->name);
    }

    public function test_names_that_differ_only_in_whitespace_are_the_same_environment(): void
    {
        [, , $project] = $this->project();

        Environment::record($project, 'staging');
        Environment::record($project, '  staging  ');

        $this->assertSame(1, Environment::query()->count());
    }

    public function test_two_projects_have_their_own_production(): void
    {
        [, $organization, $project] = $this->project();
        $other = Project::factory()->for($organization)->create(['slug' => 'blog']);

        Environment::record($project, 'production');
        Environment::record($other, 'production');

        $this->assertSame(2, Environment::query()->count());
    }

    public function test_the_management_can_hide_and_show_an_environment(): void
    {
        [$user, $organization, $project] = $this->project();
        $environment = Environment::factory()->for($project)->create(['name' => 'staging']);
        $path = "/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/umgebungen/{$environment->id}";

        $this->actingAs($user)->patch($path, ['hidden' => true])->assertSessionHasNoErrors();
        $this->assertTrue($environment->refresh()->is_hidden);

        $this->actingAs($user)->patch($path, ['hidden' => false])->assertSessionHasNoErrors();
        $this->assertFalse($environment->refresh()->is_hidden);
    }

    public function test_a_member_without_management_rights_may_not_hide_an_environment(): void
    {
        [$user, $organization, $project] = $this->project(OrganizationRole::Member);
        $environment = Environment::factory()->for($project)->create(['name' => 'staging']);

        $this->actingAs($user)
            ->patch("/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/umgebungen/{$environment->id}", ['hidden' => true])
            ->assertForbidden();

        $this->assertFalse($environment->refresh()->is_hidden);
    }

    public function test_an_environment_of_another_project_is_out_of_reach(): void
    {
        [$user, $organization, $project] = $this->project();
        $other = Project::factory()->for($organization)->create(['slug' => 'blog']);
        $environment = Environment::factory()->for($other)->create(['name' => 'staging']);

        $this->actingAs($user)
            ->patch("/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}/umgebungen/{$environment->id}", ['hidden' => true])
            ->assertNotFound();
    }

    public function test_the_project_page_lists_its_environments(): void
    {
        [$user, $organization, $project] = $this->project();
        Environment::factory()->for($project)->create(['name' => 'staging']);

        $this->actingAs($user)
            ->get("/einstellungen/organisationen/{$organization->slug}/projekte/{$project->slug}")
            ->assertOk()
            ->assertSee('staging');
    }

    public function test_deleting_a_project_takes_its_environments_with_it(): void
    {
        [, , $project] = $this->project();
        Environment::factory()->for($project)->create(['name' => 'staging']);

        $project->delete();

        $this->assertSame(0, Environment::query()->count());
    }
}
