<?php

namespace Tests\Feature\Alerts;

use App\Enums\DeliveryStatus;
use App\Enums\EventLevel;
use App\Enums\IssueAlertAction;
use App\Enums\IssueAlertComparison;
use App\Enums\IssueAlertCondition;
use App\Enums\IssueAlertFilter;
use App\Enums\IssueAlertMatch;
use App\Enums\IssueStatus;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\IssueAlertRule;
use App\Models\IssueAlertState;
use App\Models\IssueAlertTrigger;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Support\IssueAlerts\IssueAlertContext;
use App\Support\IssueAlerts\IssueAlertEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Die Auswertung: wann eine Regel greift, wann sie schweigt und was dabei
 * hinausgeht.
 *
 * Der Kern ist die Häufigkeitsbegrenzung. Sie ist der Unterschied zwischen einer
 * Alarmregel und einem Postfach voll derselben Nachricht — bei einem Ausfall
 * kommen tausend Meldungen in der Minute an, und jede einzelne trifft dieselbe
 * Regel.
 */
class IssueAlertEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein fester Zeitpunkt: die Bedingungen rechnen über gleitende Fenster,
        // und ein Test, der um Mitternacht anders ausgeht, ist keiner.
        $this->now = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);

        // Die Zustellung selbst gehört zu A1; hier zählt, **dass** etwas
        // eingereiht wird.
        Queue::fake();
    }

    /**
     * @return array{Organization, Project}
     */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();

        NotificationChannel::factory()->for($organization)->create();

        return [$organization, $project];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Project $project, array $attributes = []): Issue
    {
        return Issue::factory()->for($project)->create([
            'first_seen' => $this->now->subDays(2),
            'last_seen' => $this->now,
            'times_seen' => 1,
            ...$attributes,
        ]);
    }

    /**
     * Eine Meldung samt Gruppe — der Weg, auf dem ein Ereignis überhaupt zu
     * einem Eintrag gehört.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function event(Project $project, Issue $issue, array $attributes = []): Event
    {
        // Ein eigener Fingerabdruck je Eintrag: die Vorlage würfelt ihn sonst
        // aus drei Möglichkeiten, und zwei Einträge desselben Projekts liefen in
        // den eindeutigen Index.
        $group = EventGroup::factory()
            ->for($project)
            ->custom('issue-'.$issue->id)
            ->create(['issue_id' => $issue->id]);

        return Event::factory()
            ->for($project)
            ->for(IngestPayload::factory()->for($project), 'payload')
            ->create([
                'event_group_id' => $group->id,
                'occurred_at' => $this->now,
                'environment' => 'production',
                ...$attributes,
            ]);
    }

    /**
     * Weitere Meldungen desselben Eintrags — für die zählenden Bedingungen.
     */
    private function moreEvents(Project $project, Issue $issue, int $count, int $minutesAgo): void
    {
        $group = EventGroup::query()->where('issue_id', $issue->id)->firstOrFail();

        for ($i = 0; $i < $count; $i++) {
            Event::factory()
                ->for($project)
                ->for(IngestPayload::factory()->for($project), 'payload')
                ->create([
                    'event_group_id' => $group->id,
                    'occurred_at' => $this->now->subMinutes($minutesAgo),
                ]);
        }
    }

    /**
     * @return list<IssueAlertTrigger>
     */
    private function evaluate(Issue $issue, Event $event, bool $isNew = false, bool $escalated = false): array
    {
        return app(IssueAlertEvaluator::class)->evaluate(new IssueAlertContext(
            issue: $issue->fresh() ?? $issue,
            event: $event,
            isNew: $isNew,
            escalated: $escalated,
            occurredAt: CarbonImmutable::parse($event->occurred_at)->utc(),
        ));
    }

    private function deliveries(): int
    {
        return NotificationDelivery::query()->where('status', DeliveryStatus::Pending)->count();
    }

    public function test_a_new_issue_notifies_once_and_the_rate_limit_holds_the_rest(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $this->assertCount(1, $this->evaluate($issue, $event, isNew: true));
        $this->assertSame(1, $this->deliveries());

        // Dasselbe noch einmal, innerhalb der halben Stunde: die Regel greift,
        // die Begrenzung hält sie zurück.
        $this->assertCount(0, $this->evaluate($issue, $event, isNew: true));
        $this->assertSame(1, $this->deliveries());
        $this->assertDatabaseCount('issue_alert_triggers', 1);
    }

    public function test_the_rate_limit_lets_the_next_window_through(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->create(['frequency_minutes' => 30]);

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $this->evaluate($issue, $event, isNew: true);

        Carbon::setTestNow($this->now->addMinutes(31));

        $this->assertCount(1, $this->evaluate($issue, $event, isNew: true));
        $this->assertSame(2, $this->deliveries());
    }

    public function test_a_rule_without_a_matching_condition_stays_quiet(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        // Kein erstes Auftreten — die Regel kennt keinen anderen Anlass.
        $this->assertCount(0, $this->evaluate($issue, $event));
        $this->assertSame(0, $this->deliveries());
    }

    public function test_a_disabled_rule_is_not_evaluated(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->create(['is_active' => false]);

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $this->assertCount(0, $this->evaluate($issue, $event, isNew: true));
        $this->assertSame(0, $this->deliveries());
    }

    public function test_a_frequency_condition_counts_the_events_of_the_window(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->frequency(times: 5, minutes: 10)->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        // Vier weitere im Fenster: mit dem auslösenden sind das fünf — die
        // Bedingung sagt „öfter als fünf" und trifft damit noch nicht zu.
        $this->moreEvents($project, $issue, 4, minutesAgo: 2);
        $this->assertCount(0, $this->evaluate($issue, $event));

        $this->moreEvents($project, $issue, 2, minutesAgo: 2);
        $this->assertCount(1, $this->evaluate($issue, $event));
        $this->assertSame(1, $this->deliveries());
    }

    public function test_events_outside_the_window_do_not_count(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->frequency(times: 3, minutes: 10)->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $this->moreEvents($project, $issue, 20, minutesAgo: 120);

        $this->assertCount(0, $this->evaluate($issue, $event));
    }

    public function test_a_level_filter_narrows_the_rule_down(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->onlyLevel('fatal')->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue, ['level' => EventLevel::Error]);

        $this->assertCount(0, $this->evaluate($issue, $event, isNew: true));

        $fatal = $this->issue($project);
        $fatalEvent = $this->event($project, $fatal, ['level' => EventLevel::Fatal]);

        $this->assertCount(1, $this->evaluate($fatal, $fatalEvent, isNew: true));
    }

    public function test_at_least_error_includes_fatal(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->filters([[
            'type' => IssueAlertFilter::Level->value,
            'comparison' => IssueAlertComparison::AtLeast->value,
            'value' => 'error',
        ]])->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue, ['level' => EventLevel::Fatal]);

        $this->assertCount(1, $this->evaluate($issue, $event, isNew: true));
    }

    public function test_a_tag_filter_reads_the_tags_of_the_event(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->filters([[
            'type' => IssueAlertFilter::Tag->value,
            'comparison' => IssueAlertComparison::Equals->value,
            'key' => 'browser',
            'value' => 'Chrome',
        ]])->create();

        $issue = $this->issue($project);
        $other = $this->event($project, $issue, ['tags' => ['browser' => 'Firefox']]);

        $this->assertCount(0, $this->evaluate($issue, $other, isNew: true));

        $matching = $this->issue($project);
        $event = $this->event($project, $matching, ['tags' => ['browser' => 'Chrome']]);

        $this->assertCount(1, $this->evaluate($matching, $event, isNew: true));
    }

    public function test_an_environment_filter_ignores_the_case(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->filters([[
            'type' => IssueAlertFilter::Environment->value,
            'comparison' => IssueAlertComparison::Equals->value,
            'value' => 'Production',
        ]])->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue, ['environment' => 'production']);

        $this->assertCount(1, $this->evaluate($issue, $event, isNew: true));
    }

    public function test_all_filters_must_match_when_the_rule_says_so(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->filters([
            [
                'type' => IssueAlertFilter::Environment->value,
                'comparison' => IssueAlertComparison::Equals->value,
                'value' => 'production',
            ],
            [
                'type' => IssueAlertFilter::Release->value,
                'comparison' => IssueAlertComparison::Equals->value,
                'value' => '1.2.3',
            ],
        ], IssueAlertMatch::All)->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue, ['environment' => 'production', 'release' => '9.9.9']);

        $this->assertCount(0, $this->evaluate($issue, $event, isNew: true));
    }

    public function test_a_regression_breaks_through_the_rate_limit_but_only_once(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->conditions([
            ['type' => IssueAlertCondition::NewIssue->value],
            ['type' => IssueAlertCondition::Regression->value],
        ])->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        // Erstes Auftreten: eine Meldung, danach greift die Begrenzung.
        $this->assertCount(1, $this->evaluate($issue, $event, isNew: true));

        // Jemand erledigt den Fehler, er tritt kurz darauf wieder auf. Der
        // Rückfall ist ein neues Ereignis in der Sache und kommt durch, obwohl
        // die halbe Stunde noch nicht um ist.
        $issue->forceFill([
            'status' => IssueStatus::Resolved,
            'resolved_at' => $this->now->addMinutes(5),
        ])->save();

        Carbon::setTestNow($this->now->addMinutes(6));

        $this->assertCount(1, $this->evaluate($issue, $event));
        $this->assertSame(2, $this->deliveries());

        // Derselbe Rückfall zwei Minuten später: die Marke ist verbraucht.
        Carbon::setTestNow($this->now->addMinutes(8));

        $this->assertCount(0, $this->evaluate($issue, $event));
        $this->assertSame(2, $this->deliveries());
    }

    public function test_an_escalation_is_taken_from_the_step_before(): void
    {
        [, $project] = $this->context();
        IssueAlertRule::factory()->for($project)->conditions([
            ['type' => IssueAlertCondition::Escalation->value],
        ])->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $this->assertCount(0, $this->evaluate($issue, $event));
        $this->assertCount(1, $this->evaluate($issue, $event, escalated: true));
    }

    public function test_a_named_channel_receives_the_message_and_a_foreign_one_does_not(): void
    {
        [$organization, $project] = $this->context();
        $channel = NotificationChannel::factory()->for($organization)->create(['name' => 'Bereitschaft']);

        IssueAlertRule::factory()->for($project)->actions([[
            'type' => IssueAlertAction::Channel->value,
            'channel_id' => $channel->id,
        ]])->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $this->evaluate($issue, $event, isNew: true);

        // Genau eine Zustellung, und zwar an den benannten Kanal — nicht an den
        // zweiten, den die Organisation ebenfalls hat.
        $this->assertSame(1, $this->deliveries());
        $this->assertSame($channel->id, NotificationDelivery::query()->firstOrFail()->notification_channel_id);
    }

    public function test_a_rule_without_an_active_channel_fires_and_delivers_nothing(): void
    {
        [$organization, $project] = $this->context();
        NotificationChannel::query()->where('organization_id', $organization->id)->update(['is_active' => false]);

        IssueAlertRule::factory()->for($project)->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $triggers = $this->evaluate($issue, $event, isNew: true);

        // Die Auslösung steht im Verlauf, die Zustellung fehlt — genau die
        // Lage, die man sucht, wenn eine Meldung ausgeblieben ist.
        $this->assertCount(1, $triggers);
        $this->assertSame(0, $triggers[0]->delivery_count);
        $this->assertSame(0, $this->deliveries());
    }

    public function test_rules_of_another_project_are_not_evaluated(): void
    {
        [$organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create();

        IssueAlertRule::factory()->for($other)->create();

        $issue = $this->issue($project);
        $event = $this->event($project, $issue);

        $this->assertCount(0, $this->evaluate($issue, $event, isNew: true));
        $this->assertSame(0, $this->deliveries());
    }

    public function test_the_claim_is_taken_only_once(): void
    {
        [, $project] = $this->context();
        $rule = IssueAlertRule::factory()->for($project)->create();
        $issue = $this->issue($project);

        $now = Carbon::now();

        $this->assertTrue(IssueAlertState::claim($rule->id, $issue->id, 30, $now));
        $this->assertFalse(IssueAlertState::claim($rule->id, $issue->id, 30, $now));

        $this->assertSame(1, IssueAlertState::query()->firstOrFail()->notified_count);
    }
}
