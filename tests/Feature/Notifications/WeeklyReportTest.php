<?php

namespace Tests\Feature\Notifications;

use App\Enums\CountPeriod;
use App\Enums\IssueStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Jobs\SendWeeklyReport;
use App\Mail\WeeklyReportMail;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\PreferenceScope;
use App\Support\Reports\WeeklyProjectReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WeeklyReportTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $week;

    protected function setUp(): void
    {
        parent::setUp();

        $this->week = CarbonImmutable::parse('2026-08-03')->startOfDay();
    }

    public function test_the_report_counts_new_resolved_and_the_trend(): void
    {
        [, , $project] = $this->userWithProject();

        // Vorwoche: zehn Ereignisse. Berichtswoche: dreißig — also dreimal so
        // viele, und das ist die Zahl, die jemanden zum Nachsehen bringt.
        $old = $this->issueWithCounts($project, $this->week->subWeek(), 10, firstSeen: $this->week->subWeeks(2));
        $this->issueWithCounts($project, $this->week, 30, firstSeen: $this->week->addDay());

        $old->update(['status' => IssueStatus::Resolved, 'resolved_at' => $this->week->addDays(2)]);

        $report = WeeklyProjectReport::build($project, $this->week);

        $this->assertSame(30, $report->events);
        $this->assertSame(10, $report->previousEvents);
        $this->assertSame(200.0, $report->trendPercent());
        $this->assertSame(1, $report->newIssues);
        $this->assertSame(1, $report->resolvedIssues);
    }

    /**
     * Von null auf hundert ist keine Steigerung um hundert Prozent, sondern ein
     * Anfang — und dafür gibt es keine Zahl.
     */
    public function test_without_a_previous_week_there_is_no_trend(): void
    {
        [, , $project] = $this->userWithProject();

        $this->issueWithCounts($project, $this->week, 5);

        $this->assertNull(WeeklyProjectReport::build($project, $this->week)->trendPercent());
    }

    public function test_the_report_names_the_most_frequent_issues_and_areas(): void
    {
        [, , $project] = $this->userWithProject();

        $loud = $this->issueWithCounts($project, $this->week, 100, culprit: 'app/Kasse.php');
        $loud->update(['title' => 'RuntimeException: Kasse']);
        $quiet = $this->issueWithCounts($project, $this->week, 3, culprit: 'app/Lager.php');
        $quiet->update(['title' => 'TypeError: Lager']);

        $report = WeeklyProjectReport::build($project, $this->week);

        $this->assertSame('RuntimeException: Kasse', $report->topIssues[0]['title']);
        $this->assertSame(100, $report->topIssues[0]['count']);
        $this->assertSame('app/Kasse.php', $report->topAreas[0]['name']);
        $this->assertSame('app/Lager.php', $report->topAreas[1]['name']);
    }

    /**
     * Ein beigetretener Eintrag ist nicht verschwunden — seine Ereignisse
     * zählen beim Kopf mit, sonst zählte der Bericht eine Fehlerwelle klein.
     */
    public function test_a_merged_issue_counts_towards_its_head(): void
    {
        [, , $project] = $this->userWithProject();

        $head = $this->issueWithCounts($project, $this->week, 4);
        $member = $this->issueWithCounts($project, $this->week, 6);
        $member->forceFill(['merged_into_id' => $head->id])->save();

        $report = WeeklyProjectReport::build($project, $this->week);

        $this->assertSame(10, $report->events);
        $this->assertCount(1, $report->topIssues);
        $this->assertSame(10, $report->topIssues[0]['count']);
    }

    public function test_a_week_without_anything_produces_no_mail(): void
    {
        Mail::fake();

        [, , $project] = $this->userWithProject();

        (new SendWeeklyReport($project, $this->week->format('Y-m-d')))->handle(new NotificationPreferences);

        Mail::assertNothingSent();
    }

    public function test_every_member_that_wants_it_receives_the_report(): void
    {
        Mail::fake();

        [$wants, $organization, $project] = $this->userWithProject();
        $declines = User::factory()->create();
        $organization->setRole($declines, OrganizationRole::Member);

        NotificationPreference::put(
            $declines,
            PreferenceScope::forProject($project),
            NotificationEventType::WeeklyDigest,
            NotificationTransport::Mail,
            false,
        );

        $this->issueWithCounts($project, $this->week, 7);

        (new SendWeeklyReport($project, $this->week->format('Y-m-d')))->handle(new NotificationPreferences);

        Mail::assertSent(WeeklyReportMail::class, 1);
        Mail::assertSent(
            WeeklyReportMail::class,
            fn (WeeklyReportMail $mail): bool => $mail->hasTo($wants->email),
        );
    }

    public function test_the_command_queues_one_job_per_project(): void
    {
        Queue::fake();

        [, $organization] = $this->userWithProject();
        Project::createFor($organization, 'Lager', Platform::Php);

        $this->artisan('reports:weekly', ['--week' => $this->week->format('Y-m-d')])
            ->assertSuccessful();

        Queue::assertPushed(SendWeeklyReport::class, 2);
    }

    /**
     * Legt einen Fehler-Eintrag mit Tageszählern in der genannten Woche an.
     */
    private function issueWithCounts(
        Project $project,
        CarbonImmutable $weekStart,
        int $events,
        ?CarbonImmutable $firstSeen = null,
        string $culprit = 'app/Http/Controllers/ReportController.php',
    ): Issue {
        $first = $firstSeen ?? $weekStart->addDay();

        /** @var Issue $issue */
        $issue = Issue::factory()->create([
            'project_id' => $project->id,
            'culprit' => $culprit,
            'first_seen' => $first,
            'last_seen' => $first,
            'times_seen' => $events,
        ]);

        IssueCount::query()->create([
            'issue_id' => $issue->id,
            'period' => CountPeriod::Day,
            'window_start' => $weekStart->addDay(),
            'event_count' => $events,
        ]);

        return $issue;
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function userWithProject(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Admin)->create();
        $project = Project::createFor($organization, 'Kasse', Platform::Php);

        return [$user, $organization, $project];
    }
}
