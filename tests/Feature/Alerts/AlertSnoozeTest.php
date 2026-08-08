<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertStatus;
use App\Enums\DeliveryStatus;
use App\Enums\IssueAlertAction;
use App\Enums\OrganizationRole;
use App\Models\AlertSnooze;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertTrigger;
use App\Models\MetricAlert;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Alerts\MetricAlertEvaluator;
use App\Support\IssueAlerts\IssueAlertContext;
use App\Support\IssueAlerts\IssueAlertEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Die Stummschaltung: wer sie setzen darf und was sie tatsächlich abstellt.
 *
 * Der Kern ist die Zusage „verhindert die Benachrichtigung, nicht die
 * Auswertung". Sie ist der Unterschied zwischen Ruhe und Blindheit — wer eine
 * Nacht nicht geweckt werden will, soll am Morgen trotzdem sehen können, was in
 * dieser Nacht los war.
 */
class AlertSnoozeTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);

        // Die Zustellung selbst gehört zu A1; hier zählt, **dass** etwas
        // eingereiht wird — und vor allem, dass es das nicht wird.
        Queue::fake();
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function context(OrganizationRole $role = OrganizationRole::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        NotificationChannel::factory()->for($organization)->create();

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    private function errors(Project $project, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Event::factory()
                ->for($project)
                ->for(IngestPayload::factory()->for($project), 'payload')
                ->create([
                    'occurred_at' => $this->now->subMinutes(2),
                    'environment' => 'production',
                ]);
        }
    }

    private function deliveries(): int
    {
        return NotificationDelivery::query()->where('status', DeliveryStatus::Pending)->count();
    }

    /**
     * Der Kern: der Zustandswechsel findet statt und steht im Verlauf — nur der
     * Versand unterbleibt.
     */
    public function test_a_snooze_for_everyone_silences_the_notification_but_not_the_evaluation(): void
    {
        [$user, , $project] = $this->context();
        $alert = MetricAlert::factory()->for($project)->create(['critical_threshold' => 10.0]);

        AlertSnooze::query()->create([
            'metric_alert_id' => $alert->id,
            'created_by_id' => $user->id,
            'until' => $this->now->addHours(2),
        ]);

        $this->errors($project, 15);

        $transition = app(MetricAlertEvaluator::class)->evaluate($alert->fresh(), $this->now);

        $this->assertNotNull($transition);
        $this->assertSame(AlertStatus::Critical, $alert->fresh()->status);
        $this->assertDatabaseCount('metric_alert_transitions', 1);
        $this->assertSame(0, $this->deliveries());
    }

    public function test_a_lapsed_snooze_lets_the_alert_speak_again(): void
    {
        [$user, , $project] = $this->context();
        $alert = MetricAlert::factory()->for($project)->create(['critical_threshold' => 10.0]);

        AlertSnooze::query()->create([
            'metric_alert_id' => $alert->id,
            'created_by_id' => $user->id,
            // Vor einer Minute abgelaufen: eine Stummschaltung gilt bis zu ihrem
            // Ende und keine Sekunde länger.
            'until' => $this->now->subMinute(),
        ]);

        $this->errors($project, 15);

        app(MetricAlertEvaluator::class)->evaluate($alert->fresh(), $this->now);

        $this->assertSame(1, $this->deliveries());
    }

    /**
     * Eine persönliche Stummschaltung nimmt genau eine Person aus den
     * persönlichen Benachrichtigungen — und lässt den gemeinsamen Kanal in Ruhe.
     */
    public function test_a_personal_snooze_only_removes_that_member_from_the_personal_notifications(): void
    {
        [$user, $organization, $project] = $this->context();

        $colleague = User::factory()->create();
        $organization->setRole($colleague, OrganizationRole::Member);

        $rule = IssueAlertRule::factory()
            ->for($project)
            ->actions([['type' => IssueAlertAction::Members->value]])
            ->create();

        AlertSnooze::query()->create([
            'issue_alert_rule_id' => $rule->id,
            'user_id' => $user->id,
            'created_by_id' => $user->id,
            'until' => $this->now->addHours(2),
        ]);

        $trigger = $this->fireIssueRule($project);

        $this->assertNotNull($trigger);
        // Zwei Mitglieder, eines still: genau eine persönliche Benachrichtigung.
        $this->assertSame(1, $trigger->delivery_count);
    }

    public function test_a_snooze_for_everyone_also_silences_an_issue_rule(): void
    {
        [$user, , $project] = $this->context();

        $rule = IssueAlertRule::factory()->for($project)->create();

        AlertSnooze::query()->create([
            'issue_alert_rule_id' => $rule->id,
            'created_by_id' => $user->id,
            'until' => $this->now->addHours(2),
        ]);

        $trigger = $this->fireIssueRule($project);

        // Die Auslösung steht im Verlauf — mit null Zustellungen. Genau diese
        // Zeile beantwortet später die Frage „warum kam nichts?".
        $this->assertNotNull($trigger);
        $this->assertSame(0, $trigger->delivery_count);
        $this->assertSame(0, $this->deliveries());
    }

    public function test_only_the_administration_may_snooze_for_everyone(): void
    {
        [$user, $organization, $project] = $this->context(OrganizationRole::Member);
        $alert = MetricAlert::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('projects.alerts.snooze.store', [$organization, $project, $alert]), [
                'scope' => 'everyone',
                'minutes' => 60,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('alert_snoozes', 0);
    }

    public function test_every_member_may_snooze_a_rule_for_themselves(): void
    {
        [$user, $organization, $project] = $this->context(OrganizationRole::Member);
        $alert = MetricAlert::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('projects.alerts.snooze.store', [$organization, $project, $alert]), [
                'scope' => 'personal',
                'minutes' => 120,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('alert_snoozes', [
            'metric_alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Derselbe Knopf ein zweites Mal: die Ruhe wird verlängert und keine zweite
     * Zeile daneben gelegt.
     */
    public function test_snoozing_twice_extends_the_same_snooze(): void
    {
        [$user, $organization, $project] = $this->context();
        $alert = MetricAlert::factory()->for($project)->create();

        $url = route('projects.alerts.snooze.store', [$organization, $project, $alert]);

        $this->actingAs($user)->post($url, ['scope' => 'everyone', 'minutes' => 60]);
        $this->actingAs($user)->post($url, ['scope' => 'everyone', 'minutes' => 240]);

        $this->assertDatabaseCount('alert_snoozes', 1);
        $this->assertDatabaseHas('alert_snoozes', [
            'metric_alert_id' => $alert->id,
            'user_id' => null,
            'until' => $this->now->addMinutes(240)->toDateTimeString(),
        ]);
    }

    public function test_lifting_a_snooze_removes_only_the_chosen_scope(): void
    {
        [$user, $organization, $project] = $this->context();
        $alert = MetricAlert::factory()->for($project)->create();

        AlertSnooze::query()->create([
            'metric_alert_id' => $alert->id,
            'created_by_id' => $user->id,
            'until' => $this->now->addHours(2),
        ]);

        AlertSnooze::query()->create([
            'metric_alert_id' => $alert->id,
            'user_id' => $user->id,
            'created_by_id' => $user->id,
            'until' => $this->now->addHours(2),
        ]);

        $this->actingAs($user)
            ->delete(route('projects.alerts.snooze.destroy', [$organization, $project, $alert]), [
                'scope' => 'everyone',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('alert_snoozes', 1);
        $this->assertDatabaseHas('alert_snoozes', [
            'metric_alert_id' => $alert->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_an_unknown_duration_is_rejected(): void
    {
        [$user, $organization, $project] = $this->context();
        $alert = MetricAlert::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('projects.alerts.snooze.store', [$organization, $project, $alert]), [
                'scope' => 'everyone',
                'minutes' => 7,
            ])
            ->assertSessionHasErrors('minutes');
    }

    /**
     * Eine Regel auf „neuer Fehler" zum Greifen bringen.
     */
    private function fireIssueRule(Project $project): ?IssueAlertTrigger
    {
        $issue = Issue::factory()->for($project)->create([
            'first_seen' => $this->now->subDays(2),
            'last_seen' => $this->now,
            'times_seen' => 1,
        ]);

        $group = EventGroup::factory()
            ->for($project)
            ->custom('issue-'.$issue->id)
            ->create(['issue_id' => $issue->id]);

        $event = Event::factory()
            ->for($project)
            ->for(IngestPayload::factory()->for($project), 'payload')
            ->create([
                'event_group_id' => $group->id,
                'occurred_at' => $this->now,
                'environment' => 'production',
            ]);

        $triggers = app(IssueAlertEvaluator::class)->evaluate(new IssueAlertContext(
            issue: $issue->fresh() ?? $issue,
            event: $event,
            isNew: true,
            escalated: false,
            occurredAt: CarbonImmutable::parse($event->occurred_at)->utc(),
        ));

        return $triggers[0] ?? null;
    }
}
