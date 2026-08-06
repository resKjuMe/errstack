<?php

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_can_change_the_role_of_a_member(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $membership = $organization->setRole($member, OrganizationRole::Viewer);

        $this->actingAs($admin)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => OrganizationRole::Member->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrganizationRole::Member, $membership->refresh()->role);
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();
        $membership = $organization->setRole($member, OrganizationRole::Member);

        $this->actingAs($owner)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => 'chef'])
            ->assertSessionHasErrors('role');
    }

    public function test_nobody_changes_their_own_role(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->create();
        $membership = $organization->setRole($admin, OrganizationRole::Admin);

        $this->actingAs($admin)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => OrganizationRole::Owner->value])
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Admin, $membership->refresh()->role);
    }

    public function test_only_an_owner_hands_out_the_owner_role(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $membership = $organization->setRole($member, OrganizationRole::Member);

        $this->actingAs($admin)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => OrganizationRole::Owner->value])
            ->assertForbidden();

        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Owner);

        $this->actingAs($owner)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => OrganizationRole::Owner->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrganizationRole::Owner, $membership->refresh()->role);
    }

    public function test_administration_may_not_touch_an_owner(): void
    {
        $admin = User::factory()->create();
        $owner = User::factory()->create();
        $organization = Organization::factory()->create();
        $ownerMembership = $organization->setRole($owner, OrganizationRole::Owner);
        $organization->setRole($admin, OrganizationRole::Admin);

        $this->actingAs($admin)
            ->patch("/mitgliedschaften/{$ownerMembership->id}", ['role' => OrganizationRole::Member->value])
            ->assertForbidden();

        $this->actingAs($admin)
            ->delete("/mitgliedschaften/{$ownerMembership->id}")
            ->assertForbidden();
    }

    public function test_the_last_owner_stays_owner(): void
    {
        $owner = User::factory()->create();
        $second = User::factory()->create();
        $organization = Organization::factory()->create();
        $membership = $organization->setRole($owner, OrganizationRole::Owner);
        $lastOwner = $organization->setRole($second, OrganizationRole::Owner);

        // Solange es einen zweiten Besitzer gibt, ist die Herabstufung erlaubt …
        $this->actingAs($second)
            ->patch("/mitgliedschaften/{$membership->id}", ['role' => OrganizationRole::Member->value])
            ->assertSessionHasNoErrors();

        // … für den letzten Besitzer aber nicht mehr.
        $this->actingAs($second)
            ->delete("/mitgliedschaften/{$lastOwner->id}")
            ->assertForbidden();

        $this->assertSame(OrganizationRole::Owner, $lastOwner->refresh()->role);
    }

    public function test_administration_removes_a_member_and_its_team_assignments(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $membership = $organization->setRole($member, OrganizationRole::Member);
        $member->switchOrganization($organization);

        $team = Team::factory()->for($organization)->create();
        $team->members()->attach($member);

        $this->actingAs($admin)
            ->from("/organisationen/{$organization->slug}")
            ->delete("/mitgliedschaften/{$membership->id}")
            ->assertRedirect("/organisationen/{$organization->slug}");

        $this->assertFalse($organization->hasMember($member));
        $this->assertNull($member->refresh()->current_organization_id);
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id, 'user_id' => $member->id]);
    }

    public function test_members_may_not_remove_each_other(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();
        $membership = $organization->setRole($other, OrganizationRole::Member);

        $this->actingAs($member)
            ->delete("/mitgliedschaften/{$membership->id}")
            ->assertForbidden();

        $this->assertTrue($organization->hasMember($other));
    }

    public function test_a_member_can_leave_the_organization(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->create();
        $membership = $organization->setRole($member, OrganizationRole::Viewer);

        $this->actingAs($member)
            ->delete("/mitgliedschaften/{$membership->id}")
            ->assertRedirect('/organisationen');

        $this->assertFalse($organization->hasMember($member));
    }

    public function test_outsiders_may_not_touch_memberships(): void
    {
        $organization = Organization::factory()->create();
        $membership = $organization->setRole(User::factory()->create(), OrganizationRole::Member);

        $this->actingAs(User::factory()->create())
            ->delete("/mitgliedschaften/{$membership->id}")
            ->assertForbidden();
    }
}
