<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectKeyTest extends TestCase
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

    private function path(Organization $organization, Project $project): string
    {
        return "/organisationen/{$organization->slug}/projekte/{$project->slug}/schluessel";
    }

    public function test_a_new_project_comes_with_a_key_and_a_dsn(): void
    {
        $organization = Organization::factory()->create();

        $project = Project::createFor($organization, 'Webshop', Platform::Php);

        $this->assertCount(1, $project->keys()->get());

        $key = $project->keys()->sole();
        $this->assertSame('Standard', $key->name);
        $this->assertTrue($key->active);
        $this->assertSame(32, strlen($key->public_key));
        $this->assertStringContainsString($key->public_key, $key->dsn());
    }

    public function test_the_dsn_carries_the_public_key_and_the_project_number(): void
    {
        config(['app.url' => 'https://errstack.example/']);

        [, , $project] = $this->project();
        $key = $project->keys()->sole();

        $this->assertSame("https://{$key->public_key}@errstack.example/{$project->id}", $key->dsn());
    }

    public function test_the_dsn_keeps_port_and_subdirectory_of_the_installation(): void
    {
        config(['app.url' => 'http://localhost:8000/errstack']);

        [, , $project] = $this->project();
        $key = $project->keys()->sole();

        $this->assertSame(
            "http://{$key->public_key}@localhost:8000/errstack/{$project->id}",
            $key->dsn(),
        );
    }

    public function test_public_keys_are_unpredictable_and_unique(): void
    {
        $keys = array_map(fn (): string => ProjectKey::freshPublicKey(), range(1, 50));

        $this->assertCount(50, array_unique($keys));

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        }
    }

    public function test_the_administration_sees_the_dsn_on_the_key_page(): void
    {
        [$owner, $organization, $project] = $this->project();
        $key = $project->keys()->sole();

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Keys')
                ->has('keys', 1)
                ->where('keys.0.publicKey', $key->public_key)
                ->where('keys.0.dsn', $key->dsn())
                ->where('keys.0.active', true)
                ->where('keys.0.rateLimitPerMinute', null)
                // Der einzige Schlüssel bleibt stehen, damit das Projekt eine
                // Adresse behält.
                ->where('canDelete', false)
            );
    }

    public function test_members_without_the_right_never_get_to_see_a_dsn(): void
    {
        [, $organization, $project] = $this->project();
        $member = User::factory()->create();
        $organization->setRole($member, OrganizationRole::Member);

        $this->actingAs($member)->get($this->path($organization, $project))->assertForbidden();

        // Auch die Projektseite verrät nichts — sie verweist nur, wenn man darf.
        $this->actingAs($member)
            ->get("/organisationen/{$organization->slug}/projekte/{$project->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project.keysHref', null)
                ->where('permissions.manageKeys', false)
            );
    }

    public function test_further_keys_can_be_created(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project), [
            'name' => 'Staging',
            'rate_limit_per_minute' => 120,
        ])->assertSessionHasNoErrors();

        $key = $project->keys()->where('name', 'Staging')->sole();

        $this->assertSame(120, $key->rate_limit_per_minute);
        $this->assertTrue($key->active);
        $this->assertNotSame($project->keys()->orderBy('id')->first()->public_key, $key->public_key);
    }

    public function test_an_empty_quota_means_unlimited(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project), [
            'name' => 'Staging',
            'rate_limit_per_minute' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull($project->keys()->where('name', 'Staging')->sole()->rate_limit_per_minute);
    }

    public function test_name_and_quota_are_checked(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->post($this->path($organization, $project), [
            'name' => '',
            'rate_limit_per_minute' => 0,
        ])->assertSessionHasErrors(['name', 'rate_limit_per_minute']);

        $this->assertSame(1, $project->keys()->count());
    }

    public function test_a_key_can_be_renamed_and_get_its_own_quota(): void
    {
        [$owner, $organization, $project] = $this->project();
        $key = $project->keys()->sole();

        $this->actingAs($owner)->patch("{$this->path($organization, $project)}/{$key->id}", [
            'name' => 'Produktion',
            'rate_limit_per_minute' => 500,
        ])->assertSessionHasNoErrors();

        $key->refresh();
        $this->assertSame('Produktion', $key->name);
        $this->assertSame(500, $key->rate_limit_per_minute);
    }

    public function test_a_key_can_be_switched_off_and_on_again(): void
    {
        [$owner, $organization, $project] = $this->project();
        $key = $project->keys()->sole();
        $path = "{$this->path($organization, $project)}/{$key->id}/zustand";

        $this->actingAs($owner)->post($path)->assertSessionHasNoErrors();
        $this->assertFalse($key->refresh()->active);

        $this->actingAs($owner)->post($path)->assertSessionHasNoErrors();
        $this->assertTrue($key->refresh()->active);
    }

    public function test_a_switched_off_key_is_rejected_by_the_ingest(): void
    {
        [, , $project] = $this->project();
        $key = $project->keys()->sole();

        $this->assertNotNull(ProjectKey::findActive($key->public_key));

        $key->update(['active' => false]);

        $this->assertNull(ProjectKey::findActive($key->public_key));
    }

    public function test_rotating_replaces_the_public_key_and_keeps_the_rest(): void
    {
        [$owner, $organization, $project] = $this->project();
        $key = $project->keys()->sole();
        $key->update(['name' => 'Produktion', 'rate_limit_per_minute' => 42]);
        $before = $key->public_key;

        $this->actingAs($owner)
            ->post("{$this->path($organization, $project)}/{$key->id}/rotation")
            ->assertSessionHasNoErrors();

        $key->refresh();
        $this->assertNotSame($before, $key->public_key);
        $this->assertSame(32, strlen($key->public_key));
        $this->assertSame('Produktion', $key->name);
        $this->assertSame(42, $key->rate_limit_per_minute);
        $this->assertNull(ProjectKey::findActive($before));
    }

    public function test_a_key_can_be_deleted_but_never_the_last_one(): void
    {
        [$owner, $organization, $project] = $this->project();
        $first = $project->keys()->sole();

        $this->actingAs($owner)
            ->delete("{$this->path($organization, $project)}/{$first->id}")
            ->assertSessionHasErrors('key');
        $this->assertSame(1, $project->keys()->count());

        $second = ProjectKey::createFor($project, 'Staging');

        $this->actingAs($owner)
            ->delete("{$this->path($organization, $project)}/{$second->id}")
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $project->keys()->count());
        $this->assertNull(ProjectKey::findActive($second->public_key));
    }

    public function test_only_the_administration_may_change_keys(): void
    {
        [, $organization, $project] = $this->project();
        $key = $project->keys()->sole();
        $viewer = User::factory()->create();
        $organization->setRole($viewer, OrganizationRole::Viewer);
        $path = $this->path($organization, $project);

        $this->actingAs($viewer)->post($path, ['name' => 'Fremd'])->assertForbidden();
        $this->actingAs($viewer)->patch("{$path}/{$key->id}", ['name' => 'Fremd'])->assertForbidden();
        $this->actingAs($viewer)->post("{$path}/{$key->id}/zustand")->assertForbidden();
        $this->actingAs($viewer)->post("{$path}/{$key->id}/rotation")->assertForbidden();
        $this->actingAs($viewer)->delete("{$path}/{$key->id}")->assertForbidden();

        $this->assertSame(1, $project->keys()->count());
        $this->assertSame($key->public_key, $key->refresh()->public_key);
        $this->assertSame('Standard', $key->name);
    }

    public function test_a_key_of_another_project_is_out_of_reach(): void
    {
        [$owner, $organization, $project] = $this->project();
        $foreign = Project::factory()->for($organization)->create(['slug' => 'anderswo']);
        $foreignKey = $foreign->keys()->sole();

        $this->actingAs($owner)
            ->post("{$this->path($organization, $project)}/{$foreignKey->id}/zustand")
            ->assertNotFound();

        $this->assertTrue($foreignKey->refresh()->active);
    }

    public function test_keys_disappear_with_their_project(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)
            ->delete("/organisationen/{$organization->slug}/projekte/{$project->slug}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('project_keys', 0);
    }
}
