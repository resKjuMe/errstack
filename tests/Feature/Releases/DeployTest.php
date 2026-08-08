<?php

namespace Tests\Feature\Releases;

use App\Models\Deploy;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Auslieferungen, wie sie in der Oberfläche ankommen: als Liste an der Version
 * und als Markierung in den Verlaufsgrafiken.
 *
 * Der Zweck der Markierung ist eine einzige Frage — **hängt der Ausschlag mit
 * der Auslieferung zusammen?** Sie ist nur zu beantworten, wenn der Strich auf
 * demselben Raster sitzt wie die Balken; deshalb prüfen die Tests hier nicht
 * nur, *dass* eine Markierung entsteht, sondern *an welcher Stelle*.
 */
class DeployTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project, Release}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create([
            'name' => 'Webshop',
            'slug' => 'webshop',
            'default_environment' => 'production',
        ]);
        $release = Release::factory()->for($project)->version('1.2.0')->create();

        $user->switchOrganization($organization);

        return [$user, $organization, $project, $release];
    }

    public function test_the_release_page_lists_its_deploys(): void
    {
        [$user, , , $release] = $this->context();

        Deploy::factory()->of($release, 'staging')->create([
            'finished_at' => Carbon::now()->subHours(3),
        ]);

        Deploy::factory()->of($release, 'production')->create([
            'name' => 'Build 4711',
            'url' => 'https://ci.acme.test/runs/4711',
            'finished_at' => Carbon::now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get(route('releases.show', $release))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('releases/Show')
                ->has('deploys', 2)
                // Neueste zuerst: gefragt ist „seit wann ist das draußen?", und
                // die Antwort darauf ist die jüngste Auslieferung.
                ->where('deploys.0.environment', 'production')
                ->where('deploys.0.label', 'Build 4711')
                ->where('deploys.1.environment', 'staging')
            );
    }

    /**
     * Die Markierung sitzt in dem Fenster, in dem ausgeliefert wurde — nicht
     * am Rand der Grafik und nicht in der Mitte.
     */
    public function test_the_issue_list_marks_the_window_a_deploy_happened_in(): void
    {
        [$user, , $project, $release] = $this->context();

        // Die Zeitpunkte ausdrücklich: die Liste filtert über den gewählten
        // Zeitraum, und eine gewürfelte Spanne fiele mal hinein und mal heraus.
        Issue::factory()->for($project)->create([
            'first_seen' => Carbon::now()->subHours(3),
            'last_seen' => Carbon::now()->subHour(),
        ]);

        // Der Zeitraum „letzte 24 Stunden" wird stündlich gezeichnet; eine
        // Auslieferung vor zwei Stunden gehört deshalb in das drittletzte
        // Fenster — gezählt wird von hinten, weil das letzte die laufende
        // Stunde ist.
        Deploy::factory()->of($release, 'production')->create([
            'finished_at' => Carbon::now()->subHours(2),
        ]);

        $this->actingAs($user)
            ->get(route('issues.index', ['period' => '24h']))
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('issues/Index')->has('series.markers', 1);

                $markers = $page->toArray()['props']['series']['markers'];
                $windows = count($page->toArray()['props']['issues']['data'][0]['series']);

                $this->assertSame('1.2.0', $markers[0]['version']);
                $this->assertSame('production', $markers[0]['environment']);
                $this->assertSame($windows - 3, $markers[0]['slot']);
            });
    }

    /**
     * Der Vertrag: eine Auslieferung nach `staging` erklärt keinen Ausschlag in
     * der Produktion. Ohne Auswahl in der Filterleiste zeigt die Grafik die
     * Standard-Umgebung des Projekts — und sonst nichts.
     */
    public function test_deploys_of_another_environment_are_not_marked(): void
    {
        [$user, , $project, $release] = $this->context();

        Issue::factory()->for($project)->create([
            'first_seen' => Carbon::now()->subHours(3),
            'last_seen' => Carbon::now()->subHour(),
        ]);

        Deploy::factory()->of($release, 'staging')->create([
            'finished_at' => Carbon::now()->subHours(2),
        ]);

        $this->actingAs($user)
            ->get(route('issues.index', ['period' => '24h']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Index')
                ->has('series.markers', 0)
            );

        // Mit der Umgebung in der Filterleiste ist derselbe Deploy die richtige
        // Auskunft — die Markierung gehört zu genau einer Umgebung, und welche,
        // entscheidet die Auswahl. Die Umgebung gibt es bereits: sie ist mit
        // dem Deploy entstanden.
        $this->actingAs($user)
            ->get(route('issues.index', ['period' => '24h', 'environment' => 'staging']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Index')
                ->has('series.markers', 1)
                ->where('series.markers.0.environment', 'staging')
            );
    }
}
