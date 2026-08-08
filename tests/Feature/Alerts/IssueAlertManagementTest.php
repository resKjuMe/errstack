<?php

namespace Tests\Feature\Alerts;

use App\Enums\IssueAlertAction;
use App\Enums\IssueAlertComparison;
use App\Enums\IssueAlertCondition;
use App\Enums\IssueAlertFilter;
use App\Enums\OrganizationRole;
use App\Models\Issue;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertState;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Verwaltungsseite: wer was sehen und ändern darf, welche Regeln abgewiesen
 * werden und was die Vorschau zeigt.
 */
class IssueAlertManagementTest extends TestCase
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
            'name' => 'Neue Fehler',
            'condition_match' => 'any',
            'filter_match' => 'all',
            'conditions' => [['type' => IssueAlertCondition::NewIssue->value]],
            'filters' => [],
            'actions' => [['type' => IssueAlertAction::Channel->value, 'channel_id' => null]],
            'frequency_minutes' => 30,
            ...$overrides,
        ];
    }

    private function url(Organization $organization, Project $project, string $suffix = ''): string
    {
        return "/organisationen/{$organization->slug}/projekte/{$project->slug}/alarmregeln".$suffix;
    }

    public function test_every_member_may_look_at_the_rules(): void
    {
        [$user, $organization, $project] = $this->context(OrganizationRole::Member);
        IssueAlertRule::factory()->for($project)->create(['name' => 'Neue Fehler']);

        $this->actingAs($user)
            ->get($this->url($organization, $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/IssueAlerts')
                ->where('canManage', false)
                ->has('rules', 1)
                ->where('rules.0.name', 'Neue Fehler'));
    }

    public function test_a_member_may_not_create_a_rule(): void
    {
        [$user, $organization, $project] = $this->context(OrganizationRole::Member);

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('issue_alert_rules', 0);
    }

    public function test_an_owner_creates_a_rule(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload())
            ->assertRedirect();

        $rule = IssueAlertRule::query()->firstOrFail();

        $this->assertSame('Neue Fehler', $rule->name);
        $this->assertSame(IssueAlertCondition::NewIssue, $rule->parsedConditions()[0]->type);
        $this->assertSame(IssueAlertAction::Channel, $rule->parsedActions()[0]->type);
        $this->assertNull($rule->parsedActions()[0]->channelId);
    }

    public function test_a_rule_needs_a_trigger_and_an_action(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload(['conditions' => [], 'actions' => []]))
            ->assertSessionHasErrors(['conditions', 'actions']);
    }

    public function test_a_counting_trigger_needs_a_count_and_a_window(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload([
                'conditions' => [['type' => IssueAlertCondition::Frequency->value]],
            ]))
            ->assertSessionHasErrors(['conditions.0.value', 'conditions.0.window']);
    }

    public function test_a_comparison_that_does_not_fit_the_filter_is_rejected(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload([
                'filters' => [[
                    'type' => IssueAlertFilter::Age->value,
                    'comparison' => IssueAlertComparison::Contains->value,
                    'value' => 'Chrome',
                ]],
            ]))
            ->assertSessionHasErrors('filters.0.comparison');
    }

    public function test_a_tag_filter_needs_its_tag_name(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload([
                'filters' => [[
                    'type' => IssueAlertFilter::Tag->value,
                    'comparison' => IssueAlertComparison::Equals->value,
                    'value' => 'Chrome',
                ]],
            ]))
            ->assertSessionHasErrors('filters.0.key');
    }

    public function test_a_channel_of_another_organization_is_rejected(): void
    {
        [$user, $organization, $project] = $this->context();
        $foreign = NotificationChannel::factory()->for(Organization::factory()->create())->create();

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload([
                'actions' => [['type' => IssueAlertAction::Channel->value, 'channel_id' => $foreign->id]],
            ]))
            ->assertSessionHasErrors('actions.0.channel_id');
    }

    public function test_the_number_of_rules_per_project_is_capped(): void
    {
        [$user, $organization, $project] = $this->context();

        IssueAlertRule::factory()
            ->for($project)
            ->count(IssueAlertRule::MAX_PER_PROJECT)
            ->sequence(fn ($sequence): array => ['name' => 'Regel '.$sequence->index])
            ->create();

        $this->actingAs($user)
            ->post($this->url($organization, $project), $this->payload())
            ->assertSessionHasErrors('name');
    }

    public function test_changing_a_rule_clears_its_rate_limit(): void
    {
        [$user, $organization, $project] = $this->context();
        $rule = IssueAlertRule::factory()->for($project)->create();
        $issue = Issue::factory()->for($project)->create();

        IssueAlertState::claim($rule->id, $issue->id, 30, Carbon::now());
        $this->assertDatabaseCount('issue_alert_states', 1);

        $this->actingAs($user)
            ->patch($this->url($organization, $project, '/'.$rule->id), $this->payload(['name' => 'Alles']))
            ->assertRedirect();

        // Die neue Fassung beschreibt einen anderen Anlass — „für diesen Fehler
        // schon gemeldet" wäre eine Aussage über eine Regel, die es nicht mehr
        // gibt.
        $this->assertDatabaseCount('issue_alert_states', 0);
        $this->assertSame('Alles', $rule->fresh()->name);
    }

    public function test_a_rule_can_be_switched_off_and_deleted(): void
    {
        [$user, $organization, $project] = $this->context();
        $rule = IssueAlertRule::factory()->for($project)->create();

        $this->actingAs($user)
            ->post($this->url($organization, $project, '/'.$rule->id.'/zustand'))
            ->assertRedirect();

        $this->assertFalse($rule->fresh()->is_active);

        $this->actingAs($user)
            ->delete($this->url($organization, $project, '/'.$rule->id))
            ->assertRedirect();

        $this->assertDatabaseCount('issue_alert_rules', 0);
    }

    public function test_a_rule_of_another_project_is_not_reachable(): void
    {
        [$user, $organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create(['slug' => 'kasse']);
        $rule = IssueAlertRule::factory()->for($other)->create();

        $this->actingAs($user)
            ->delete($this->url($organization, $project, '/'.$rule->id))
            ->assertNotFound();
    }

    public function test_the_preview_shows_the_issues_a_rule_would_catch(): void
    {
        [$user, $organization, $project] = $this->context();

        $fresh = Issue::factory()->for($project)->create([
            'title' => 'TypeError: brandneu',
            'first_seen' => now()->subDay(),
            'last_seen' => now(),
        ]);

        Issue::factory()->for($project)->create([
            'title' => 'RuntimeException: uralt',
            'first_seen' => now()->subMonths(3),
            'last_seen' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson($this->url($organization, $project, '/vorschau'), $this->payload())
            ->assertOk();

        // Nur der junge Eintrag: „neuer Fehler" heißt im Rückblick „in den
        // letzten Tagen zum ersten Mal aufgetreten".
        $response->assertJsonPath('matched', 1);
        $response->assertJsonPath('issues.0.id', $fresh->id);
    }

    public function test_the_preview_does_not_save_anything(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->postJson($this->url($organization, $project, '/vorschau'), $this->payload())
            ->assertOk();

        $this->assertDatabaseCount('issue_alert_rules', 0);
    }

    public function test_every_member_may_use_the_preview(): void
    {
        [$user, $organization, $project] = $this->context(OrganizationRole::Member);

        $this->actingAs($user)
            ->postJson($this->url($organization, $project, '/vorschau'), $this->payload())
            ->assertOk();
    }
}
