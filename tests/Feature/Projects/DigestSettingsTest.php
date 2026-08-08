<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Notifications\DigestBundlingTest;
use Tests\TestCase;

/**
 * Die Seite mit den Bündelungs-Einstellungen eines Projekts.
 *
 * Geprüft wird die Bedienung, nicht die Wirkung — dass aus zehn Meldungen eine
 * Sammelnachricht wird, steht in {@see DigestBundlingTest}. Hier geht es um die
 * beiden Zusagen der Seite: jedes Mitglied darf nachsehen, warum eine Meldung
 * spät kam, und nur die Verwaltung darf daran drehen.
 */
class DigestSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_current_window_and_limits(): void
    {
        [$owner, $organization, $project] = $this->project();
        $project->update(['digest_window_minutes' => 5]);

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Digest')
                ->where('project.windowMinutes', 5)
                ->where('project.minEvents', 2)
                ->where('project.maxEvents', 25)
                ->where('canManage', true)
                ->where('waiting', 0)
                ->etc()
            );
    }

    public function test_the_settings_survive_a_reload(): void
    {
        [$owner, $organization, $project] = $this->project();
        $path = $this->path($organization, $project);

        $this->actingAs($owner)->patch($path, [
            'digest_window_minutes' => 15,
            'digest_min_events' => 3,
            'digest_max_events' => 40,
        ])->assertRedirect();

        $this->assertSame(15, $project->fresh()?->digest_window_minutes);

        $this->actingAs($owner)->get($path)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project.windowMinutes', 15)
                ->where('project.minEvents', 3)
                ->where('project.maxEvents', 40)
                ->etc()
            );
    }

    /**
     * Wäre die Höchstzahl nicht größer als die Mindestzahl, käme nie eine
     * Sammelnachricht zustande: der Korb wäre voll, bevor sich das Bündeln
     * lohnt.
     */
    public function test_the_maximum_has_to_be_above_the_minimum(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->patch($this->path($organization, $project), [
            'digest_window_minutes' => 5,
            'digest_min_events' => 10,
            'digest_max_events' => 10,
        ])->assertSessionHasErrors('digest_max_events');
    }

    public function test_a_member_may_look_but_not_change(): void
    {
        [$member, $organization, $project] = $this->project(OrganizationRole::Member);

        $this->actingAs($member)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', false)
                ->etc()
            );

        $this->actingAs($member)->patch($this->path($organization, $project), [
            'digest_window_minutes' => 30,
            'digest_min_events' => 2,
            'digest_max_events' => 25,
        ])->assertForbidden();

        $this->assertSame(0, $project->fresh()?->digest_window_minutes);
    }

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
        return "/organisationen/{$organization->slug}/projekte/{$project->slug}/buendelung";
    }
}
