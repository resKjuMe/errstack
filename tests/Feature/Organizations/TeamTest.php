<?php

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_can_create_a_team(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->post("/organisationen/{$organization->slug}/teams", ['name' => 'Plattform'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teams', [
            'organization_id' => $organization->id,
            'name' => 'Plattform',
        ]);
    }

    public function test_members_may_not_create_a_team(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();

        $this->actingAs($member)
            ->post("/organisationen/{$organization->slug}/teams", ['name' => 'Heimlich'])
            ->assertForbidden();

        $this->assertDatabaseCount('teams', 0);
    }

    public function test_team_names_are_unique_within_an_organization_only(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        Team::factory()->for($organization)->create(['name' => 'Plattform']);

        $this->actingAs($owner)
            ->post("/organisationen/{$organization->slug}/teams", ['name' => 'Plattform'])
            ->assertSessionHasErrors('name');

        // In einer anderen Organisation darf derselbe Name wieder vorkommen.
        $other = Organization::factory()->withMember($owner)->create();

        $this->actingAs($owner)
            ->post("/organisationen/{$other->slug}/teams", ['name' => 'Plattform'])
            ->assertSessionHasNoErrors();
    }

    public function test_every_member_may_look_at_a_team(): void
    {
        $viewer = User::factory()->create();
        $organization = Organization::factory()->withMember($viewer, OrganizationRole::Viewer)->create();
        $team = Team::factory()->for($organization)->create();

        $this->actingAs($viewer)
            ->get("/teams/{$team->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('teams/Show')
                ->where('team.name', $team->name)
                ->where('permissions.manage', false)
            );
    }

    public function test_outsiders_do_not_get_to_see_a_team(): void
    {
        $team = Team::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/teams/{$team->id}")
            ->assertForbidden();
    }

    public function test_only_administration_may_rename_or_delete_a_team(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();
        $team = Team::factory()->for($organization)->create(['name' => 'Alt']);

        $this->actingAs($member)->patch("/teams/{$team->id}", ['name' => 'Neu'])->assertForbidden();
        $this->actingAs($member)->delete("/teams/{$team->id}")->assertForbidden();

        $admin = User::factory()->create();
        $organization->setRole($admin, OrganizationRole::Admin);

        $this->actingAs($admin)
            ->patch("/teams/{$team->id}", ['name' => 'Neu'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Neu', $team->refresh()->name);

        $this->actingAs($admin)
            ->delete("/teams/{$team->id}")
            ->assertRedirect("/organisationen/{$organization->slug}");
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_members_of_the_organization_can_be_assigned_and_removed(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $organization->setRole($member, OrganizationRole::Member);
        $team = Team::factory()->for($organization)->create();

        $this->actingAs($owner)
            ->from("/teams/{$team->id}")
            ->post("/teams/{$team->id}/mitglieder", ['user_id' => $member->id])
            ->assertRedirect("/teams/{$team->id}");

        $this->assertDatabaseHas('team_user', ['team_id' => $team->id, 'user_id' => $member->id]);

        // Zweimal hinzufügen ändert nichts — die Zuordnung bleibt einmalig.
        $this->actingAs($owner)->post("/teams/{$team->id}/mitglieder", ['user_id' => $member->id]);
        $this->assertSame(1, $team->members()->count());

        $this->actingAs($owner)
            ->delete("/teams/{$team->id}/mitglieder/{$member->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id, 'user_id' => $member->id]);
    }

    public function test_someone_outside_the_organization_cannot_join_a_team(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $team = Team::factory()->for($organization)->create();
        $outsider = User::factory()->create();

        $this->actingAs($owner)
            ->post("/teams/{$team->id}/mitglieder", ['user_id' => $outsider->id])
            ->assertForbidden();

        $this->assertDatabaseCount('team_user', 0);
    }

    public function test_the_team_page_offers_only_members_of_the_organization(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $organization->setRole($member, OrganizationRole::Member);
        $team = Team::factory()->for($organization)->create();
        $team->members()->attach($owner);
        User::factory()->create();

        $this->actingAs($owner)
            ->get("/teams/{$team->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('members', 1)
                ->has('candidates', 1)
                ->where('candidates.0.id', $member->id)
                ->where('permissions.manage', true)
            );
    }
}
