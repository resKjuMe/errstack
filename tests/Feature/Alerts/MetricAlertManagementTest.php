<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\MetricAlert;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Verwaltungsseite: wer was sehen und ändern darf, welche Einstellungen
 * abgewiesen werden und was die Vorschau zeigt.
 */
class MetricAlertManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function context(OrganizationRole $role = OrganizationRole::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Fehlerflut',
            'metric' => AlertMetric::ErrorCount->value,
            'direction' => 'above',
            'comparison' => 'absolute',
            'environment' => null,
            'transaction_name' => null,
            'window_minutes' => 5,
            'warning_threshold' => 5,
            'critical_threshold' => 10,
            'resolve_threshold' => null,
            'minimum_samples' => 0,
            ...$overrides,
        ];
    }

    public function test_a_member_sees_the_alerts_but_may_not_manage_them(): void
    {
        [$user, $organization, $project] = $this->context(OrganizationRole::Member);

        MetricAlert::factory()->for($project)->create(['name' => 'Fehlerflut']);

        $this->actingAs($user)
            ->get(route('projects.alerts.index', [$organization, $project]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/Alerts')
                ->has('alerts', 1)
                ->where('alerts.0.name', 'Fehlerflut')
                ->where('canManage', false)
                ->etc()
            );
    }

    public function test_a_stranger_is_turned_away(): void
    {
        [, $organization, $project] = $this->context();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.alerts.index', [$organization, $project]))
            ->assertForbidden();
    }

    public function test_the_administration_creates_an_alert(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post(route('projects.alerts.store', [$organization, $project]), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('metric_alerts', [
            'project_id' => $project->id,
            'name' => 'Fehlerflut',
            'metric' => 'error_count',
            'warning_threshold' => 5,
            'critical_threshold' => 10,
            'status' => 'ok',
        ]);
    }

    public function test_a_member_may_not_create_an_alert(): void
    {
        [$user, $organization, $project] = $this->context(OrganizationRole::Member);

        $this->actingAs($user)
            ->post(route('projects.alerts.store', [$organization, $project]), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('metric_alerts', 0);
    }

    public function test_an_alert_without_any_threshold_is_rejected(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post(
                route('projects.alerts.store', [$organization, $project]),
                $this->payload(['warning_threshold' => null, 'critical_threshold' => null]),
            )
            ->assertSessionHasErrors('warning_threshold');
    }

    public function test_thresholds_in_the_wrong_order_are_rejected(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post(
                route('projects.alerts.store', [$organization, $project]),
                $this->payload(['warning_threshold' => 20, 'critical_threshold' => 10]),
            )
            ->assertSessionHasErrors('critical_threshold');
    }

    public function test_a_resolve_threshold_above_the_firing_one_is_rejected(): void
    {
        [$user, $organization, $project] = $this->context();

        // Sonst wäre der Alarm in dem Augenblick aufgelöst, in dem er auslöst.
        $this->actingAs($user)
            ->post(
                route('projects.alerts.store', [$organization, $project]),
                $this->payload(['resolve_threshold' => 8]),
            )
            ->assertSessionHasErrors('resolve_threshold');
    }

    public function test_a_transaction_name_on_an_error_metric_is_rejected(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post(
                route('projects.alerts.store', [$organization, $project]),
                $this->payload(['transaction_name' => 'GET /kasse']),
            )
            ->assertSessionHasErrors('transaction_name');
    }

    public function test_changing_an_alert_resets_its_state(): void
    {
        [$user, $organization, $project] = $this->context();

        $alert = MetricAlert::factory()->for($project)->firing()->create([
            'last_value' => 42.0,
            'last_evaluated_at' => now(),
        ]);

        $this->actingAs($user)
            ->patch(
                route('projects.alerts.update', [$organization, $project, $alert]),
                $this->payload(['name' => 'Fehlerflut', 'critical_threshold' => 99]),
            )
            ->assertRedirect();

        $fresh = $alert->fresh();

        // „Kritisch seit gestern" wäre nach einer geänderten Schwelle eine
        // Aussage über eine Regel, die es nicht mehr gibt.
        $this->assertSame(AlertStatus::Ok, $fresh->status);
        $this->assertNull($fresh->status_since);
        $this->assertNull($fresh->last_value);
        $this->assertNull($fresh->last_evaluated_at);
    }

    public function test_switching_an_alert_off_keeps_it_but_calms_it(): void
    {
        [$user, $organization, $project] = $this->context();

        $alert = MetricAlert::factory()->for($project)->firing()->create();

        $this->actingAs($user)
            ->post(route('projects.alerts.toggle', [$organization, $project, $alert]))
            ->assertRedirect();

        $fresh = $alert->fresh();

        $this->assertFalse($fresh->is_active);
        $this->assertSame(AlertStatus::Ok, $fresh->status);
    }

    public function test_an_alert_can_be_deleted(): void
    {
        [$user, $organization, $project] = $this->context();

        $alert = MetricAlert::factory()->for($project)->create();

        $this->actingAs($user)
            ->delete(route('projects.alerts.destroy', [$organization, $project, $alert]))
            ->assertRedirect();

        $this->assertDatabaseCount('metric_alerts', 0);
    }

    public function test_an_alert_of_another_project_is_out_of_reach(): void
    {
        [$user, $organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create(['slug' => 'kasse']);

        $alert = MetricAlert::factory()->for($other)->create();

        // `scopeBindings`: der Alarm ist nur über sein eigenes Projekt
        // erreichbar.
        $this->actingAs($user)
            ->delete(route('projects.alerts.destroy', [$organization, $project, $alert]))
            ->assertNotFound();
    }

    public function test_the_preview_shows_the_history_with_the_thresholds_on_top(): void
    {
        [$user, $organization, $project] = $this->context();

        Event::factory()
            ->for($project)
            ->for(IngestPayload::factory()->for($project), 'payload')
            ->create(['occurred_at' => now()->subMinutes(2)]);

        $response = $this->actingAs($user)->postJson(
            route('projects.alerts.preview', [$organization, $project]),
            $this->payload(),
        );

        $response->assertOk()
            ->assertJsonPath('windowMinutes', 5)
            ->assertJsonCount(2, 'thresholds')
            ->assertJsonPath('thresholds.0.status', 'warning')
            ->assertJsonPath('thresholds.1.status', 'critical');

        // Vierundzwanzig Fenster, ältestes zuerst — eine Grafik wird von links
        // nach rechts gelesen.
        $points = $response->json('points');

        $this->assertCount(24, $points);
        $this->assertSame(1.0, (float) $points[23]['value']);
        $this->assertSame(0.0, (float) $points[0]['value']);
    }
}
