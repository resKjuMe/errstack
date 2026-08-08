<?php

namespace Tests\Feature\Issues;

use App\Enums\CountPeriod;
use App\Enums\EventLevel;
use App\Enums\IssueActivityType;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Jobs\DeliverPersonalNotification;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueCount;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PreferenceScope;
use App\Support\Issues\IssuePrioritySweep;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die automatisch ermittelte Wichtigkeit und die Eskalation stummgeschalteter
 * Fehler (S11).
 *
 * Geprüft wird das, was die Aufgabe zusagt, und zwar von beiden Seiten: dass
 * die Ableitung im Hintergrund einordnet **und** dass sie eine Einordnung von
 * Hand nicht anfasst. Der zweite Teil ist der, der ohne Test still kaputtgeht —
 * er fällt erst auf, wenn jemand seine Einstellung von gestern nicht mehr
 * vorfindet.
 */
class IssuePriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Eine feste Stunde, weil die Eskalation gegen die zuletzt
        // **vollständige** Stunde misst: mit einer Uhr, die auf einer
        // Stundengrenze steht, wäre das Fenster eine Zufallsfrage.
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:30:00', 'UTC'));

        Queue::fake();
    }

    /**
     * @return array{User, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create();

        $user->switchOrganization($organization);

        return [$user, $project];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Project $project, array $attributes = []): Issue
    {
        return Issue::factory()->for($project)->create([
            'first_seen' => Carbon::now()->subDays(30),
            'last_seen' => Carbon::now()->subMinutes(5),
            ...$attributes,
        ]);
    }

    /**
     * Legt Stundenzähler an: `$hours` Stunden zurück, je Stunde `$count`
     * Ereignisse.
     */
    private function counts(Issue $issue, int $fromHoursAgo, int $toHoursAgo, int $count): void
    {
        $now = CarbonImmutable::now();

        for ($hour = $fromHoursAgo; $hour > $toHoursAgo; $hour--) {
            IssueCount::query()->create([
                'issue_id' => $issue->id,
                'period' => CountPeriod::Hour,
                'window_start' => CountPeriod::Hour->windowFor($now->subHours($hour)),
                'event_count' => $count,
            ]);
        }
    }

    private function sweep(): array
    {
        return app(IssuePrioritySweep::class)->run();
    }

    public function test_a_frequent_crash_becomes_urgent_and_the_derivation_is_in_the_history(): void
    {
        [, $project] = $this->context();

        $issue = $this->issue($project, ['level' => EventLevel::Fatal, 'users_seen' => 25]);
        $this->counts($issue, 24, 0, 30);

        $result = $this->sweep();

        $this->assertSame(1, $result['changed']);
        $this->assertSame(IssuePriority::High, $issue->refresh()->priority);

        $activity = IssueActivity::query()->where('issue_id', $issue->id)->sole();

        $this->assertSame(IssueActivityType::PriorityChanged, $activity->type);
        // Ohne handelndes Konto: der Durchlauf ist niemand.
        $this->assertNull($activity->actor_name);
        $this->assertSame('derived', $activity->data['mode']);
        $this->assertSame('high', $activity->data['priority']);
        $this->assertSame('medium', $activity->data['previous']);
        $this->assertSame(
            ['level', 'events', 'users'],
            array_column($activity->data['reasons'], 'key'),
        );
    }

    public function test_a_rare_info_message_ends_up_at_the_bottom(): void
    {
        [, $project] = $this->context();

        $issue = $this->issue($project, ['level' => EventLevel::Info, 'users_seen' => 1]);
        $this->counts($issue, 3, 2, 1);

        $this->sweep();

        $this->assertSame(IssuePriority::Low, $issue->refresh()->priority);
    }

    public function test_the_derivation_leaves_a_manual_priority_alone(): void
    {
        [$user, $project] = $this->context();

        $issue = $this->issue($project, ['level' => EventLevel::Fatal, 'users_seen' => 25]);
        $this->counts($issue, 24, 0, 30);

        $this->actingAs($user)
            ->from(route('issues.show', $issue))
            ->post(route('issues.actions.store'), [
                'action' => 'priority',
                'priority' => 'low',
                'issues' => [$issue->id],
            ])
            ->assertRedirect();

        $issue->refresh();

        $this->assertSame(IssuePriority::Low, $issue->priority);
        $this->assertTrue($issue->priority_locked);

        $result = $this->sweep();

        // Betrachtet wird er, geändert wird er nicht.
        $this->assertSame(1, $result['examined']);
        $this->assertSame(0, $result['changed']);
        $this->assertSame(IssuePriority::Low, $issue->refresh()->priority);

        $activity = IssueActivity::query()->where('issue_id', $issue->id)->sole();

        $this->assertSame(IssueActivityType::PriorityChanged, $activity->type);
        $this->assertSame($user->name, $activity->actor_name);
        $this->assertSame('manual', $activity->data['mode']);
    }

    public function test_choosing_automatic_hands_the_issue_back_to_the_derivation(): void
    {
        [$user, $project] = $this->context();

        $issue = $this->issue($project, [
            'level' => EventLevel::Fatal,
            'users_seen' => 25,
            'priority' => IssuePriority::Low,
            'priority_locked' => true,
        ]);
        $this->counts($issue, 24, 0, 30);

        $this->actingAs($user)
            ->from(route('issues.show', $issue))
            ->post(route('issues.actions.store'), [
                'action' => 'priority',
                'priority' => 'auto',
                'issues' => [$issue->id],
            ])
            ->assertRedirect();

        $issue->refresh();

        // Der Schalter fällt, die Stufe bleibt vorerst stehen: zwischen Klick
        // und Durchlauf soll dort nichts stehen, was niemand behauptet hat.
        $this->assertFalse($issue->priority_locked);
        $this->assertSame(IssuePriority::Low, $issue->priority);

        $this->sweep();

        $this->assertSame(IssuePriority::High, $issue->refresh()->priority);
    }

    public function test_an_ignored_issue_far_above_its_course_is_woken_and_reported(): void
    {
        [$user, $project] = $this->context();

        $issue = $this->issue($project, [
            'status' => IssueStatus::Ignored,
            'ignored_at' => Carbon::now()->subDays(10),
            'ignored_by_id' => $user->id,
            'level' => EventLevel::Error,
        ]);

        // Der Zustandswechsel eines Fehlers geht per Vorgabe nur ins Postfach
        // der Anwendung und nicht per E-Mail
        // (NotificationEventType::defaultFor()). Hier wird die E-Mail
        // ausdrücklich eingeschaltet: geprüft werden soll, dass gemeldet wird
        // und an wen — nicht, was die Vorgabe ist.
        NotificationPreference::put(
            $user,
            PreferenceScope::global(),
            NotificationEventType::WorkflowChange,
            NotificationTransport::Mail,
            true,
        );

        // Zehn Tage Rauschen — und in der letzten vollen Stunde eine Welle.
        $this->counts($issue, 168, 1, 2);
        $this->counts($issue, 1, 0, 400);

        $result = $this->sweep();

        $this->assertSame(1, $result['escalated']);

        $issue->refresh();

        $this->assertSame(IssueStatus::Unresolved, $issue->status);
        $this->assertNotNull($issue->escalated_at);
        // Die Bedingung der Stummschaltung ist mit ihr weg: ein Eintrag, der
        // wieder offen ist, darf keine Frist von vorletzter Woche mitschleppen.
        $this->assertNull($issue->ignored_at);

        $activity = IssueActivity::query()
            ->where('issue_id', $issue->id)
            ->where('type', IssueActivityType::Escalated)
            ->sole();

        $this->assertSame(400, $activity->data['observed']);

        // Gemeldet wird an den, der stummgeschaltet hat — seine Aussage ist die,
        // die gerade widerlegt wurde.
        Queue::assertPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->event === NotificationEventType::WorkflowChange
                && $job->user->is($user),
        );
    }

    public function test_an_ignored_issue_within_its_course_stays_quiet(): void
    {
        [$user, $project] = $this->context();

        $issue = $this->issue($project, [
            'status' => IssueStatus::Ignored,
            'ignored_at' => Carbon::now()->subDays(10),
            'ignored_by_id' => $user->id,
        ]);

        // Gleichmäßig laut: 40 Meldungen je Stunde, auch in der letzten.
        $this->counts($issue, 168, 0, 40);

        $result = $this->sweep();

        $this->assertSame(0, $result['escalated']);
        $this->assertSame(IssueStatus::Ignored, $issue->refresh()->status);

        Queue::assertNotPushed(DeliverPersonalNotification::class);
    }

    public function test_a_resolved_issue_keeps_the_priority_it_had(): void
    {
        [, $project] = $this->context();

        $issue = $this->issue($project, [
            'status' => IssueStatus::Resolved,
            'resolved_at' => Carbon::now()->subHour(),
            'level' => EventLevel::Fatal,
            'users_seen' => 25,
        ]);
        $this->counts($issue, 24, 0, 30);

        $result = $this->sweep();

        $this->assertSame(0, $result['examined']);
        $this->assertSame(IssuePriority::DEFAULT, $issue->refresh()->priority);
    }

    public function test_the_command_reports_what_it_did(): void
    {
        [, $project] = $this->context();

        $issue = $this->issue($project, ['level' => EventLevel::Fatal, 'users_seen' => 25]);
        $this->counts($issue, 24, 0, 30);

        $this->artisan('issues:prioritize')
            ->expectsOutputToContain('1 neu eingeordnet')
            ->assertExitCode(0);
    }

    public function test_the_priority_is_searchable_under_both_spellings(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, ['priority' => IssuePriority::High, 'title' => 'Dringend']);
        $this->issue($project, ['priority' => IssuePriority::Low, 'title' => 'Nachrangig']);

        // Beide Schreibweisen, weil ein unbekanntes Feld in dieser Suchsprache
        // kein Fehler ist, sondern ein Merkmal: `issue.priority:high` fände ohne
        // den zweiten Namen stillschweigend nichts.
        foreach (['priority:high', 'issue.priority:high'] as $query) {
            $this->actingAs($user)
                ->get(route('issues.index', ['q' => $query]))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('issues.data', 1)
                    ->where('issues.data.0.title', 'Dringend')
                );
        }
    }

    public function test_an_unknown_priority_is_refused(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)
            ->from(route('issues.show', $issue))
            ->post(route('issues.actions.store'), [
                'action' => 'priority',
                'priority' => 'sehr hoch',
                'issues' => [$issue->id],
            ])
            ->assertSessionHasErrors('priority');

        $this->assertFalse($issue->refresh()->priority_locked);
    }
}
