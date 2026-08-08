<?php

namespace Tests\Feature\Issues;

use App\Models\Event;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Detailseite eines Fehlers: was sie zeigt, was sie aus dem Eintrag nimmt
 * und wer sie sehen darf.
 */
class IssueDetailTest extends TestCase
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

    private function issue(Project $project): Issue
    {
        return Issue::factory()->for($project)->create([
            'title' => 'RuntimeException: Zahlung fehlgeschlagen',
            'times_seen' => 1234,
            'users_seen' => 56,
            'first_seen' => Carbon::now()->subDays(3),
            'last_seen' => Carbon::now()->subHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(Issue $issue, array $attributes = []): Event
    {
        $group = EventGroup::factory()
            ->for($issue->project)
            ->for($issue)
            ->create();

        return Event::factory()
            ->for($issue->project)
            ->create([
                'event_group_id' => $group->id,
                ...$attributes,
            ]);
    }

    public function test_the_page_shows_the_stack_trace_with_file_line_function_and_code(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->event($issue, [
            'exceptions' => [[
                'type' => 'RuntimeException',
                'value' => 'Zahlung fehlgeschlagen',
                'frames' => [
                    [
                        'filename' => 'vendor/laravel/framework/src/Router.php',
                        'function' => 'dispatch',
                        'lineno' => 700,
                        'in_app' => false,
                    ],
                    [
                        'filename' => 'app/Http/Controllers/PaymentController.php',
                        'function' => 'charge',
                        'lineno' => 42,
                        'in_app' => true,
                        'pre_context' => ['$amount = $order->total();'],
                        'context_line' => 'throw new RuntimeException(...);',
                        'post_context' => ['}'],
                    ],
                ],
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Show')
                ->has('event.exceptions', 1)
                ->where('event.exceptions.0.type', 'RuntimeException')
                ->where('event.exceptions.0.value', 'Zahlung fehlgeschlagen')
                ->has('event.exceptions.0.frames', 2)
                // Innerste Stelle zuerst: der eigene Rahmen lag zuletzt in der
                // gelieferten Reihenfolge und steht deshalb jetzt oben.
                ->where('event.exceptions.0.frames.0.filename', 'app/Http/Controllers/PaymentController.php')
                ->where('event.exceptions.0.frames.0.function', 'charge')
                ->where('event.exceptions.0.frames.0.lineno', 42)
                ->where('event.exceptions.0.frames.0.inApp', true)
                ->where('event.exceptions.0.frames.1.inApp', false)
            );
    }

    public function test_the_code_context_is_numbered_around_the_failing_line(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->event($issue, [
            'exceptions' => [[
                'type' => 'RuntimeException',
                'frames' => [[
                    'filename' => 'app/Payment.php',
                    'lineno' => 42,
                    'in_app' => true,
                    'pre_context' => ['eins', 'zwei'],
                    'context_line' => 'drei',
                    'post_context' => ['vier'],
                ]],
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('event.exceptions.0.frames.0.context', 4)
                ->where('event.exceptions.0.frames.0.context.0.number', 40)
                ->where('event.exceptions.0.frames.0.context.0.current', false)
                ->where('event.exceptions.0.frames.0.context.2.number', 42)
                ->where('event.exceptions.0.frames.0.context.2.text', 'drei')
                ->where('event.exceptions.0.frames.0.context.2.current', true)
                ->where('event.exceptions.0.frames.0.context.3.number', 43)
            );
    }

    public function test_the_cause_chain_starts_with_what_the_application_saw(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        // Gespeichert liegt die Kette von der ältesten Ursache an; gelesen wird
        // sie andersherum.
        $this->event($issue, [
            'exceptions' => [
                ['type' => 'PDOException', 'value' => 'Verbindung verweigert'],
                ['type' => 'RuntimeException', 'value' => 'Zahlung fehlgeschlagen'],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('event.exceptions.0.type', 'RuntimeException')
                ->where('event.exceptions.0.isCause', false)
                ->where('event.exceptions.1.type', 'PDOException')
                ->where('event.exceptions.1.isCause', true)
            );
    }

    public function test_breadcrumbs_arrive_as_a_timeline(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->event($issue, [
            'breadcrumbs' => [
                [
                    'category' => 'navigation',
                    'level' => 'info',
                    'message' => 'Zur Kasse',
                    'timestamp' => '2026-03-10T11:59:00Z',
                ],
                [
                    'category' => 'http',
                    'level' => 'error',
                    'message' => 'POST /zahlung 500',
                    'timestamp' => '2026-03-10T11:59:30Z',
                    'data' => ['status' => 500],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('event.breadcrumbs', 2)
                ->where('event.breadcrumbs.0.category', 'navigation')
                ->where('event.breadcrumbs.0.level', 'info')
                ->where('event.breadcrumbs.1.message', 'POST /zahlung 500')
                ->where('event.breadcrumbs.1.data.status', 500)
                ->whereNot('event.breadcrumbs.1.timestampLabel', null)
            );
    }

    public function test_the_aggregates_come_from_the_issue_and_not_from_the_events(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        // Eine einzige Meldung, aber vierstellige Zähler: die Seite darf nicht
        // zählen, was sie sieht, sondern liest den Eintrag.
        $this->event($issue);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.timesSeen', 1234)
                ->where('issue.usersSeen', 56)
                ->where('issue.project.name', 'Webshop')
            );
    }

    public function test_a_message_without_an_exception_is_shown_as_a_message(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->event($issue, [
            'exceptions' => null,
            'message' => ['formatted' => 'Nur eine Notiz'],
        ]);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('event.exceptions', 0)
                ->where('event.message.formatted', 'Nur eine Notiz')
            );
    }

    public function test_an_issue_without_events_still_opens(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Show')
                ->where('event', null)
                ->where('navigation', null)
                ->where('rawHref', null)
                ->where('issue.timesSeen', 1234)
            );
    }

    public function test_the_detail_page_stops_at_the_organization_of_the_viewer(): void
    {
        [$user] = $this->context();

        $foreign = Project::factory()->for(Organization::factory())->create();
        $issue = $this->issue($foreign);

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertForbidden();
    }

    public function test_the_detail_page_needs_a_signed_in_viewer(): void
    {
        [, , $project] = $this->context();

        $this->get(route('issues.show', $this->issue($project)))->assertRedirect(route('login'));
    }

    public function test_the_raw_view_carries_the_parsed_report_and_the_original(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);
        $event = $this->event($issue, ['title' => 'RuntimeException: Zahlung fehlgeschlagen']);

        $this->actingAs($user)
            ->getJson(route('issues.events.raw', [$issue, $event]))
            ->assertOk()
            ->assertJsonPath('event.title', 'RuntimeException: Zahlung fehlgeschlagen')
            ->assertJsonPath('event.event_id', $event->event_id)
            ->assertJsonMissingPath('event.ingest_payload_id')
            ->assertJsonStructure(['event', 'original']);
    }

    public function test_a_report_of_another_issue_is_not_shown_under_this_one(): void
    {
        [$user, , $project] = $this->context();

        $issue = $this->issue($project);
        $other = $this->issue($project);
        $foreignEvent = $this->event($other);

        $this->actingAs($user)
            ->get(route('issues.events.show', [$issue, $foreignEvent]))
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson(route('issues.events.raw', [$issue, $foreignEvent]))
            ->assertNotFound();
    }

    public function test_the_list_links_into_the_detail_page(): void
    {
        [$user, , $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.data.0.href', route('issues.show', $issue))
            );
    }
}
