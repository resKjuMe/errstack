<?php

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overview_lists_only_own_organizations(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->withMember($user, OrganizationRole::Member)->create(['name' => 'Eigene']);
        Organization::factory()->create(['name' => 'Fremde']);

        $this->actingAs($user)->get('/organisationen')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('organizations/Index')
                ->has('organizations', 1)
                ->where('organizations.0.name', $own->name)
                ->where('organizations.0.roleLabel', 'Mitglied')
            );
    }

    public function test_creating_an_organization_makes_the_creator_its_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/organisationen', ['name' => 'Neue Firma'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/organisationen/neue-firma');

        $organization = Organization::query()->where('slug', 'neue-firma')->firstOrFail();

        $this->assertSame(OrganizationRole::Owner, $organization->roleFor($user));
        $this->assertSame($organization->id, $user->refresh()->current_organization_id);
    }

    public function test_the_slug_stays_unique_across_organizations_of_the_same_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/organisationen', ['name' => 'Doppelt']);
        $this->actingAs($user)->post('/organisationen', ['name' => 'Doppelt']);

        $this->assertSame(['doppelt', 'doppelt-2'], Organization::query()->orderBy('id')->pluck('slug')->all());
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/organisationen', ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_outsiders_do_not_get_to_see_an_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/organisationen/{$organization->slug}")
            ->assertForbidden();
    }

    public function test_every_role_may_look_at_the_organization(): void
    {
        foreach (OrganizationRole::cases() as $role) {
            $user = User::factory()->create();
            $organization = Organization::factory()->withMember($user, $role)->create();

            $this->actingAs($user)
                ->get("/organisationen/{$organization->slug}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('organizations/Show')
                    ->where('viewer.role', $role->value)
                );
        }
    }

    public function test_only_administration_and_above_may_rename(): void
    {
        $allowed = [OrganizationRole::Owner, OrganizationRole::Admin];

        foreach (OrganizationRole::cases() as $role) {
            $user = User::factory()->create();
            $organization = Organization::factory()->withMember($user, $role)->create(['name' => 'Alt']);

            $response = $this->actingAs($user)
                ->patch("/organisationen/{$organization->slug}", ['name' => 'Neu']);

            if (in_array($role, $allowed, true)) {
                $response->assertSessionHasNoErrors();
                $this->assertSame('Neu', $organization->refresh()->name);
            } else {
                $response->assertForbidden();
                $this->assertSame('Alt', $organization->refresh()->name);
            }
        }
    }

    public function test_renaming_keeps_the_slug_so_links_stay_valid(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create(['name' => 'Alt', 'slug' => 'alt']);

        $this->actingAs($user)->patch('/organisationen/alt', ['name' => 'Ganz neu']);

        $this->assertSame('alt', $organization->refresh()->slug);
    }

    public function test_only_the_owner_may_delete_the_organization(): void
    {
        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();

        $this->actingAs($admin)
            ->delete("/organisationen/{$organization->slug}")
            ->assertForbidden();

        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Owner);

        $this->actingAs($owner)
            ->delete("/organisationen/{$organization->slug}")
            ->assertRedirect('/organisationen');

        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
    }

    public function test_deleting_an_organization_clears_it_as_the_active_one(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $user->switchOrganization($organization);

        $this->actingAs($user)->delete("/organisationen/{$organization->slug}");

        $this->assertNull($user->refresh()->current_organization_id);
    }

    public function test_the_active_organization_can_be_switched(): void
    {
        $user = User::factory()->create();
        $first = Organization::factory()->withMember($user)->create();
        $second = Organization::factory()->withMember($user)->create();
        $user->switchOrganization($first);

        $this->actingAs($user)
            ->from("/organisationen/{$second->slug}")
            ->post("/organisationen/{$second->slug}/wechseln")
            ->assertRedirect("/organisationen/{$second->slug}");

        $this->assertSame($second->id, $user->refresh()->current_organization_id);
    }

    public function test_switching_to_a_foreign_organization_is_refused(): void
    {
        $user = User::factory()->create();
        $foreign = Organization::factory()->create();

        $this->actingAs($user)
            ->post("/organisationen/{$foreign->slug}/wechseln")
            ->assertForbidden();

        $this->assertNull($user->refresh()->current_organization_id);
    }

    public function test_the_organization_pages_stay_closed_for_guests(): void
    {
        $this->get('/organisationen')->assertRedirect('/login');
    }
}
