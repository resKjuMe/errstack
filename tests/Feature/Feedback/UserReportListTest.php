<?php

namespace Tests\Feature\Feedback;

use App\Enums\DeliveryStatus;
use App\Enums\OrganizationRole;
use App\Enums\UserReportStatus;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\UserReport;
use App\Support\Feedback\UserReportNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Rückmeldungs-Liste: was drinsteht, wonach sie sich filtern lässt, wer sie
 * bearbeiten darf — und dass eine neue Zuschrift überhaupt jemanden erreicht.
 */
class UserReportListTest extends TestCase
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
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(Project $project, array $attributes = []): UserReport
    {
        return UserReport::factory()->for($project)->create([
            'received_at' => Carbon::now()->subHour(),
            ...$attributes,
        ]);
    }

    public function test_the_list_shows_the_reports_of_the_selected_projects(): void
    {
        [$user, , $project] = $this->context();

        $this->report($project, ['comments' => 'Der Warenkorb bleibt leer.']);

        // Ein zweites Projekt einer fremden Organisation darf nicht auftauchen.
        $this->report(Project::factory()->create(), ['comments' => 'Aus einem fremden Haus.']);

        $this->actingAs($user)
            ->get(route('feedback.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('feedback/Index')
                ->has('reports.data', 1)
                ->where('reports.data.0.comments', 'Der Warenkorb bleibt leer.'));
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        [$user, , $project] = $this->context();

        $this->report($project, ['comments' => 'Neu', 'status' => UserReportStatus::New]);
        $this->report($project, ['comments' => 'Erledigt', 'status' => UserReportStatus::Done]);

        $this->actingAs($user)
            ->get(route('feedback.index', ['status' => UserReportStatus::Done->value]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reports.data', 1)
                ->where('reports.data.0.comments', 'Erledigt'));
    }

    public function test_the_list_can_be_filtered_by_assignment(): void
    {
        [$user, , $project] = $this->context();

        $this->report($project, ['comments' => 'Bei mir', 'assigned_to' => $user->id]);
        $this->report($project, ['comments' => 'Bei niemandem']);

        $this->actingAs($user)
            ->get(route('feedback.index', ['assignee' => 'ich']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reports.data', 1)
                ->where('reports.data.0.comments', 'Bei mir'));

        $this->actingAs($user)
            ->get(route('feedback.index', ['assignee' => 'niemand']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('reports.data', 1)
                ->where('reports.data.0.comments', 'Bei niemandem'));
    }

    /**
     * Der Zeitraum wirkt auf den Eingang: eine Zuschrift ist ein Zeitpunkt und
     * keine Spanne.
     */
    public function test_the_period_limits_the_list(): void
    {
        [$user, , $project] = $this->context();

        $this->report($project, ['received_at' => Carbon::now()->subDays(40)]);

        $this->actingAs($user)
            ->get(route('feedback.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reports.data', 0));
    }

    public function test_the_status_can_be_changed(): void
    {
        [$user, , $project] = $this->context();

        $report = $this->report($project);

        $this->actingAs($user)
            ->patch(route('feedback.status', $report), ['status' => UserReportStatus::Done->value])
            ->assertRedirect();

        $this->assertSame(UserReportStatus::Done, $report->refresh()->status);
    }

    public function test_a_report_can_be_assigned_and_released_again(): void
    {
        [$user, $organization, $project] = $this->context();

        $colleague = User::factory()->create();
        $organization->setRole($colleague, OrganizationRole::Member);

        $report = $this->report($project);

        $this->actingAs($user)
            ->patch(route('feedback.assignment', $report), ['assigned_to' => $colleague->id])
            ->assertRedirect();

        $report->refresh();
        $this->assertSame($colleague->id, $report->assigned_to);
        $this->assertNotNull($report->assigned_at);

        $this->actingAs($user)
            ->patch(route('feedback.assignment', $report), ['assigned_to' => null])
            ->assertRedirect();

        $report->refresh();
        $this->assertNull($report->assigned_to);
        $this->assertNull($report->assigned_at);
    }

    /**
     * Zugewiesen wird nur an Mitglieder. Sonst hinge eine Zuschrift bei jemandem,
     * der sie nicht einmal öffnen kann.
     */
    public function test_a_report_cannot_be_assigned_to_an_outsider(): void
    {
        [$user, , $project] = $this->context();

        $outsider = User::factory()->create();
        $report = $this->report($project);

        $this->actingAs($user)
            ->patch(route('feedback.assignment', $report), ['assigned_to' => $outsider->id])
            ->assertSessionHasErrors('assigned_to');

        $this->assertNull($report->refresh()->assigned_to);
    }

    /**
     * Wer nicht zur Organisation gehört, kommt an die Rückmeldung nicht heran —
     * auch nicht über eine geratene Kennung.
     */
    public function test_an_outsider_may_not_change_a_report(): void
    {
        [, , $project] = $this->context();

        $report = $this->report($project);

        $this->actingAs(User::factory()->create())
            ->patch(route('feedback.status', $report), ['status' => UserReportStatus::Spam->value])
            ->assertForbidden();
    }

    /**
     * Eine neue Rückmeldung erreicht die Kanäle der Organisation. Ohne das wäre
     * die Liste eine Seite, auf die niemand von selbst schaut.
     */
    public function test_a_new_report_reaches_the_notification_channels(): void
    {
        Queue::fake();

        [, $organization, $project] = $this->context();

        NotificationChannel::factory()->for($organization)->create(['is_active' => true]);

        $report = $this->report($project, ['comments' => 'Die Kasse hängt.']);

        app(UserReportNotifier::class)->send($report);

        $delivery = NotificationDelivery::query()->sole();

        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame('Die Kasse hängt.', $delivery->payload['body'] ?? null);
    }
}
