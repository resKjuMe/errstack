<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertStatus;
use App\Enums\DeliveryStatus;
use App\Enums\OrganizationRole;
use App\Models\AlertSnooze;
use App\Models\Issue;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertTrigger;
use App\Models\MetricAlert;
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
 * Die Übersichtsseite: was sie zeigt, wonach sie einschränkt und was sie
 * verschweigt.
 *
 * Die beiden Alarm-Arten stehen in verschiedenen Tabellen und werden hier zu
 * einer Liste — das ist der eigentliche Gegenstand dieser Prüfungen. Wer eine
 * Benachrichtigung bekommen hat, weiß nicht, ob ein Schwellwert oder eine
 * Fehler-Regel dahintersteckte, und soll es auch nicht wissen müssen.
 */
class AlertOverviewTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
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

    private function transition(MetricAlert $alert, AlertStatus $to, CarbonImmutable $at): void
    {
        $alert->transitions()->create([
            'from_status' => AlertStatus::Ok,
            'to_status' => $to,
            'value' => 42.0,
            'threshold' => 10.0,
            'baseline' => null,
            'occurred_at' => $at,
        ]);
    }

    private function trigger(IssueAlertRule $rule, Project $project, CarbonImmutable $at, int $deliveries = 1): void
    {
        $issue = Issue::factory()->for($project)->create([
            'first_seen' => $at,
            'last_seen' => $at,
            'times_seen' => 1,
        ]);

        IssueAlertTrigger::query()->create([
            'issue_alert_rule_id' => $rule->id,
            'issue_id' => $issue->id,
            'conditions' => ['new_issue'],
            'delivery_count' => $deliveries,
            'occurred_at' => $at,
        ]);
    }

    public function test_the_overview_lists_both_kinds_of_rules(): void
    {
        [$user, $organization, $project] = $this->context();

        MetricAlert::factory()->for($project)->create(['name' => 'Fehlerflut']);
        IssueAlertRule::factory()->for($project)->create(['name' => 'Neue Fehler']);

        $this->actingAs($user)
            ->get(route('projects.alert-overview.index', [$organization, $project]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/AlertOverview')
                ->has('rows', 2)
                ->where('rows.0.name', 'Fehlerflut')
                ->where('rows.0.kind', 'metric')
                ->where('rows.1.name', 'Neue Fehler')
                ->where('rows.1.kind', 'issue'));
    }

    /**
     * Eine abgeschaltete Regel ist nicht „in Ordnung" — sie wird gar nicht
     * ausgewertet. Diese Verwechslung wäre die gefährlichste Auskunft der Seite.
     */
    public function test_a_disabled_alert_is_shown_as_disabled_and_not_as_healthy(): void
    {
        [$user, $organization, $project] = $this->context();

        MetricAlert::factory()->for($project)->create([
            'is_active' => false,
            'status' => AlertStatus::Ok,
        ]);

        $this->actingAs($user)
            ->get(route('projects.alert-overview.index', [$organization, $project]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.state', 'off'));
    }

    public function test_the_history_holds_both_kinds_and_the_period_narrows_it(): void
    {
        [$user, $organization, $project] = $this->context();

        $alert = MetricAlert::factory()->for($project)->create();
        $rule = IssueAlertRule::factory()->for($project)->create();

        $this->transition($alert, AlertStatus::Critical, $this->now->subHours(2));
        $this->trigger($rule, $project, $this->now->subHours(3));
        // Außerhalb der letzten vierundzwanzig Stunden.
        $this->trigger($rule, $project, $this->now->subDays(3));

        $this->actingAs($user)
            ->get(route('projects.alert-overview.index', [$organization, $project]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('history', 2));

        $this->actingAs($user)
            ->get(route('projects.alert-overview.index', [$organization, $project]).'?zeitraum=7d')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('history', 3));
    }

    public function test_the_state_filter_narrows_the_history(): void
    {
        [$user, $organization, $project] = $this->context();

        $alert = MetricAlert::factory()->for($project)->create();
        $rule = IssueAlertRule::factory()->for($project)->create();

        $this->transition($alert, AlertStatus::Critical, $this->now->subHours(1));
        $this->transition($alert, AlertStatus::Ok, $this->now->subMinutes(30));
        $this->trigger($rule, $project, $this->now->subHours(2));

        $url = route('projects.alert-overview.index', [$organization, $project]);

        $this->actingAs($user)
            ->get($url.'?zustand=critical')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('history', 1)
                ->where('history.0.state', 'critical'));

        // „Ausgelöst" meint die Fehler-Regeln: sie haben keinen Zustand, sie
        // greifen. Zustandswechsel gehören deshalb nicht dazu.
        $this->actingAs($user)
            ->get($url.'?zustand=fired')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('history', 1)
                ->where('history.0.kind', 'issue'));
    }

    /**
     * Ein Link aus einer älteren Fassung soll die Seite nicht zerschießen — er
     * zeigt dann eben den voreingestellten Zeitraum.
     */
    public function test_an_unknown_filter_value_falls_back_instead_of_failing(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)
            ->get(route('projects.alert-overview.index', [$organization, $project]).'?zeitraum=vorgestern&zustand=lila')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alertFilter.period', '24h')
                ->where('alertFilter.state', 'all'));
    }

    public function test_the_detail_page_shows_the_history_and_the_deliveries_of_that_rule(): void
    {
        [$user, $organization, $project] = $this->context();

        $channel = NotificationChannel::factory()->for($organization)->create();
        $alert = MetricAlert::factory()->for($project)->create(['name' => 'Fehlerflut']);

        $this->transition($alert, AlertStatus::Critical, $this->now->subHours(1));

        $channel->deliveries()->create([
            'subject' => 'Alarm ausgelöst',
            'reference' => 'ALERT-'.$alert->id,
            'payload' => ['title' => 'Alarm ausgelöst'],
            'status' => DeliveryStatus::Sent,
            'is_test' => false,
        ]);

        // Eine Zustellung einer anderen Regel — sie hat auf dieser Seite nichts
        // zu suchen.
        $channel->deliveries()->create([
            'subject' => 'Etwas anderes',
            'reference' => 'ALERT-'.($alert->id + 99),
            'payload' => ['title' => 'Etwas anderes'],
            'status' => DeliveryStatus::Sent,
            'is_test' => false,
        ]);

        $this->actingAs($user)
            ->get(route('projects.alert-overview.metric', [$organization, $project, $alert]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('projects/AlertDetail')
                ->where('alert.name', 'Fehlerflut')
                ->has('history', 1)
                ->has('deliveries', 1)
                ->where('deliveries.0.subject', 'Alarm ausgelöst')
                ->has('metricChart'));
    }

    /**
     * Eine Fehler-Regel misst nichts — die Kurve der Kennzahl entfällt, und die
     * Zustellungen kommen über den gemeinsamen Anfang ihrer Kennung.
     */
    public function test_the_detail_page_of_an_issue_rule_finds_the_deliveries_of_all_its_issues(): void
    {
        [$user, $organization, $project] = $this->context();

        $channel = NotificationChannel::factory()->for($organization)->create();
        $rule = IssueAlertRule::factory()->for($project)->create(['name' => 'Neue Fehler']);

        foreach ([7, 9] as $issueId) {
            $channel->deliveries()->create([
                'subject' => 'Regel griff für Fehler '.$issueId,
                'reference' => 'ISSUE-'.$rule->id.'-'.$issueId,
                'payload' => ['title' => 'Regel griff'],
                'status' => DeliveryStatus::Sent,
                'is_test' => false,
            ]);
        }

        // Eine andere Regel, deren Kennung mit derselben Ziffer beginnt: der
        // Bindestrich hinter der Regel-Kennung ist der Grund, warum sie nicht
        // mitkommt.
        $channel->deliveries()->create([
            'subject' => 'Andere Regel',
            'reference' => 'ISSUE-'.($rule->id * 10).'-1',
            'payload' => ['title' => 'Andere Regel'],
            'status' => DeliveryStatus::Sent,
            'is_test' => false,
        ]);

        $this->actingAs($user)
            ->get(route('projects.alert-overview.issue', [$organization, $project, $rule]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alert.kind', 'issue')
                ->has('deliveries', 2)
                ->where('metricChart', null));
    }

    /**
     * Eine laufende Stummschaltung steht an der Regel — mit ihrem Ende und, bei
     * „für alle", mit dem, der sie gesetzt hat.
     */
    public function test_a_running_snooze_is_visible_on_the_rule(): void
    {
        [$user, $organization, $project] = $this->context();

        $alert = MetricAlert::factory()->for($project)->create();

        AlertSnooze::query()->create([
            'metric_alert_id' => $alert->id,
            'created_by_id' => $user->id,
            'until' => $this->now->addHours(2),
        ]);

        $this->actingAs($user)
            ->get(route('projects.alert-overview.index', [$organization, $project]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.snooze.everyone.by', $user->name)
                ->where('rows.0.snooze.mine', null));
    }

    /**
     * Ein Schwellwert-Alarm meldet nur an gemeinsame Kanäle — eine persönliche
     * Stummschaltung bliebe dort wirkungslos, und die Seite sagt das.
     */
    public function test_a_metric_alert_reports_that_a_personal_snooze_has_no_effect(): void
    {
        [$user, $organization, $project] = $this->context();

        MetricAlert::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('projects.alert-overview.index', [$organization, $project]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.snooze.personalEffective', false));
    }

    public function test_a_stranger_may_not_see_the_overview(): void
    {
        [, $organization, $project] = $this->context();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('projects.alert-overview.index', [$organization, $project]))
            ->assertForbidden();
    }
}
