<?php

namespace Tests\Feature\Repositories;

use App\Enums\OrganizationRole;
use App\Models\Commit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Repositories verbinden, ansehen und lösen.
 *
 * Verbinden heißt hier eintragen: solange es keine Anbindung gibt (X1/X2), holt
 * niemand von selbst Commits ab, sondern eine Bauumgebung übergibt sie unter
 * genau dem Namen, der hier steht.
 */
class RepositoryManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization}
     */
    private function context(OrganizationRole $role = OrganizationRole::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();

        $user->switchOrganization($organization);

        return [$user, $organization];
    }

    public function test_a_repository_can_be_connected(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post(route('organizations.repositories.store', $organization), [
                'name' => '  acme/webshop  ',
                'url' => 'https://github.com/acme/webshop',
            ])
            ->assertRedirect();

        $repository = Repository::query()->sole();

        // Vereinheitlicht: „ acme/webshop " und „acme/webshop" sind dasselbe
        // Repository, sonst passte die Übergabe aus der Bauumgebung nicht dazu.
        $this->assertSame('acme/webshop', $repository->name);
        $this->assertSame($organization->id, $repository->organization_id);
        $this->assertSame(Repository::PROVIDER_MANUAL, $repository->provider);
    }

    public function test_the_same_repository_cannot_be_connected_twice(): void
    {
        [$user, $organization] = $this->context();

        Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);

        $this->actingAs($user)
            ->post(route('organizations.repositories.store', $organization), ['name' => 'acme/webshop'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Repository::query()->count());
    }

    public function test_the_list_shows_the_repositories_with_their_commit_count(): void
    {
        [$user, $organization] = $this->context();

        $repository = Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);
        Commit::factory()->count(2)->for($repository)->create();

        // Ein Repository einer fremden Organisation gehört nicht auf diese
        // Seite — es ist die Herkunft **ihres** Codes, nicht unseres.
        Repository::factory()->create(['name' => 'fremd/andere']);

        $this->actingAs($user)
            ->get(route('organizations.repositories.index', $organization))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('repositories/Index')
                ->has('repositories', 1)
                ->where('repositories.0.name', 'acme/webshop')
                ->where('repositories.0.commitCount', 2)
                ->where('canManage', true)
            );
    }

    /**
     * Ansehen darf jedes Mitglied — die Liste sagt nur, woher der Code kommt.
     * Verbinden und lösen ist Sache der Verwaltung.
     */
    public function test_a_plain_member_may_look_but_not_connect(): void
    {
        [$user, $organization] = $this->context(OrganizationRole::Member);

        $this->actingAs($user)
            ->get(route('organizations.repositories.index', $organization))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actingAs($user)
            ->post(route('organizations.repositories.store', $organization), ['name' => 'acme/webshop'])
            ->assertForbidden();
    }

    public function test_someone_outside_the_organization_cannot_see_the_list(): void
    {
        [, $organization] = $this->context();

        $outsider = User::factory()->create();
        Organization::factory()->withMember($outsider)->create();

        $this->actingAs($outsider)
            ->get(route('organizations.repositories.index', $organization))
            ->assertForbidden();
    }

    /**
     * Ein gelöstes Repository nimmt seine Commits mit — und damit den Inhalt
     * jeder Auslieferung, die aus ihm bestand. Die Auslieferungen selbst
     * bleiben: sie sind aus den Meldungen entstanden und nicht aus dem
     * Repository.
     */
    public function test_disconnecting_removes_the_commits_but_keeps_the_releases(): void
    {
        [$user, $organization] = $this->context();

        $project = Project::factory()->for($organization)->create();
        $release = Release::factory()->for($project)->version('1.2.0')->create();

        $repository = Repository::factory()->for($organization)->create();
        $commit = Commit::factory()->for($repository)->create();
        $release->commits()->attach($commit->id, ['position' => 0]);

        $this->actingAs($user)
            ->delete(route('repositories.destroy', $repository))
            ->assertRedirect();

        $this->assertSame(0, Repository::query()->count());
        $this->assertSame(0, Commit::query()->count());
        $this->assertSame(0, $release->commits()->count());
        $this->assertNotNull(Release::query()->find($release->id));
    }
}
