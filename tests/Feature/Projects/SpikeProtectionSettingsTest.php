<?php

namespace Tests\Feature\Projects;

use App\Enums\OrganizationRole;
use App\Models\IngestVolume;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SpikeProtectionState;
use App\Models\User;
use App\Support\Ingest\Spikes\SpikeBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Ingest\SpikeProtectionTest;
use Tests\TestCase;

/**
 * Die Seite des Ausschlag-Schutzes eines Projekts (A7).
 *
 * Geprüft wird die Bedienung, nicht die Wirkung — dass eine Flut gedrosselt und
 * das Verworfene gezählt wird, steht in {@see SpikeProtectionTest}. Hier geht es
 * um die drei Zusagen der Seite: jedes Mitglied darf nachsehen, warum ihm
 * Meldungen fehlen; nur die Verwaltung darf daran drehen; und eine laufende
 * Drosselung lässt sich von Hand aufheben.
 */
class SpikeProtectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_settings_and_what_it_measures_against(): void
    {
        [$owner, $organization, $project] = $this->project();
        $project->update(['spike_protection_enabled' => true, 'spike_minimum_events' => 10]);
        $this->history($project, 20);

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Spikes')
                ->where('project.enabled', true)
                ->where('detection.baseline', 20)
                // Fünffaches von 20 gegen eine Untergrenze von 10: das
                // Vielfache gewinnt.
                ->where('detection.threshold', 100)
                ->where('detection.ready', true)
                ->where('current', null)
                ->where('canManage', true)
                ->etc()
            );
    }

    /**
     * Solange zu wenig Verlauf vorliegt, entscheidet der Schutz nicht — und die
     * Seite sagt das. Ohne diese Auskunft hielte man ihn für kaputt.
     */
    public function test_a_project_without_history_is_shown_as_not_ready(): void
    {
        [$owner, $organization, $project] = $this->project();
        $project->update(['spike_protection_enabled' => true]);

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('detection.ready', false)
                ->where('detection.threshold', 0)
                ->where('detection.requiredSamples', SpikeBaseline::MINIMUM_SAMPLES)
                ->etc()
            );
    }

    public function test_the_settings_survive_a_reload(): void
    {
        [$owner, $organization, $project] = $this->project();
        $path = $this->path($organization, $project);

        $this->actingAs($owner)->patch($path, [
            'spike_protection_enabled' => true,
            'spike_threshold_factor' => 3.5,
            'spike_minimum_events' => 250,
            'spike_release_minutes' => 20,
        ])->assertRedirect();

        $this->assertTrue($project->fresh()?->spike_protection_enabled);

        $this->actingAs($owner)->get($path)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project.factor', 3.5)
                ->where('project.minimumEvents', 250)
                ->where('project.releaseMinutes', 20)
                ->etc()
            );
    }

    /**
     * Bei einem Faktor von 1 wäre die Schwelle der Vergleichswert selbst — und
     * der wird definitionsgemäß in etwa der Hälfte aller Minuten
     * überschritten. Das Projekt läge dauerhaft in der Drosselung.
     */
    public function test_the_factor_has_to_be_above_one(): void
    {
        [$owner, $organization, $project] = $this->project();

        $this->actingAs($owner)->patch($this->path($organization, $project), [
            'spike_protection_enabled' => true,
            'spike_threshold_factor' => 1,
            'spike_minimum_events' => 250,
            'spike_release_minutes' => 20,
        ])->assertSessionHasErrors('spike_threshold_factor');
    }

    public function test_the_running_throttle_is_shown_and_can_be_lifted(): void
    {
        [$owner, $organization, $project] = $this->project();
        $project->update(['spike_protection_enabled' => true]);

        $state = SpikeProtectionState::factory()->for($project)->create(['discarded' => 4_200]);

        $this->actingAs($owner)->get($this->path($organization, $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('current.id', $state->id)
                ->where('current.discarded', 4200)
                ->etc()
            );

        $this->actingAs($owner)->post($this->path($organization, $project).'/aufhebung')
            ->assertRedirect();

        $lifted = $state->fresh();

        $this->assertNotNull($lifted->ended_at);
        $this->assertSame($owner->id, $lifted->released_by_id);
    }

    public function test_a_member_may_look_but_neither_change_nor_lift(): void
    {
        [$member, $organization, $project] = $this->project(OrganizationRole::Member);
        SpikeProtectionState::factory()->for($project)->create();

        $this->actingAs($member)->get($this->path($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', false)
                ->etc()
            );

        $this->actingAs($member)->patch($this->path($organization, $project), [
            'spike_protection_enabled' => true,
            'spike_threshold_factor' => 5,
            'spike_minimum_events' => 500,
            'spike_release_minutes' => 15,
        ])->assertForbidden();

        $this->actingAs($member)->post($this->path($organization, $project).'/aufhebung')
            ->assertForbidden();

        $this->assertFalse($project->fresh()?->spike_protection_enabled);
        $this->assertNotNull(SpikeProtectionState::open($project));
    }

    private function history(Project $project, int $perMinute): void
    {
        IngestVolume::factory()->for($project)->count(SpikeBaseline::MINIMUM_SAMPLES + 5)->sequence(
            fn ($sequence): array => [
                'bucket' => Carbon::now()->startOfMinute()->subMinutes($sequence->index + 1),
                'quantity' => $perMinute,
            ],
        )->create();
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
        return "/organisationen/{$organization->slug}/projekte/{$project->slug}/ausschlagschutz";
    }
}
