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
 * Das Blättern zwischen den Meldungen eines Fehlers.
 */
class EventNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Issue $issue;

    /** @var array<int, Event> nach Alter geordnet: 0 = älteste */
    private array $events = [];

    private EventGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $organization = Organization::factory()->withMember($this->user)->create();
        $project = Project::factory()->for($organization)->create();

        $this->user->switchOrganization($organization);

        $this->issue = Issue::factory()->for($project)->create();

        $this->group = EventGroup::factory()->for($project)->for($this->issue)->create();

        foreach ([3, 2, 1] as $index => $hoursAgo) {
            $this->events[$index] = Event::factory()->for($project)->create([
                'event_group_id' => $this->group->id,
                'occurred_at' => Carbon::now()->subHours($hoursAgo),
            ]);
        }
    }

    public function test_without_a_report_in_the_address_the_newest_one_opens(): void
    {
        $this->actingAs($this->user)
            ->get(route('issues.show', $this->issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('event.id', $this->events[2]->id)
                // Am neuesten Ereignis gibt es keinen Weg nach vorn.
                ->where('navigation.newer', null)
                ->where('navigation.newest', null)
                ->where('navigation.older', route('issues.events.show', [$this->issue, $this->events[1]]))
                ->where('navigation.oldest', route('issues.events.show', [$this->issue, $this->events[0]]))
            );
    }

    public function test_from_the_middle_every_direction_leads_somewhere(): void
    {
        $this->actingAs($this->user)
            ->get(route('issues.events.show', [$this->issue, $this->events[1]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('event.id', $this->events[1]->id)
                ->where('navigation.newer', route('issues.events.show', [$this->issue, $this->events[2]]))
                ->where('navigation.newest', route('issues.events.show', [$this->issue, $this->events[2]]))
                ->where('navigation.older', route('issues.events.show', [$this->issue, $this->events[0]]))
                ->where('navigation.oldest', route('issues.events.show', [$this->issue, $this->events[0]]))
            );
    }

    public function test_at_the_oldest_report_there_is_no_way_further_back(): void
    {
        $this->actingAs($this->user)
            ->get(route('issues.events.show', [$this->issue, $this->events[0]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.older', null)
                ->where('navigation.oldest', null)
                ->where('navigation.newer', route('issues.events.show', [$this->issue, $this->events[1]]))
            );
    }

    public function test_reports_of_the_same_second_keep_a_stable_order(): void
    {
        $project = $this->issue->project;

        $moment = Carbon::now()->subMinutes(10);

        $first = Event::factory()->for($project)->create([
            'event_group_id' => $this->group->id,
            'occurred_at' => $moment,
        ]);

        $second = Event::factory()->for($project)->create([
            'event_group_id' => $this->group->id,
            'occurred_at' => $moment,
        ]);

        // Gleicher Zeitpunkt: die Kennung entscheidet, und zwar in beide
        // Richtungen dieselbe.
        $this->actingAs($this->user)
            ->get(route('issues.events.show', [$this->issue, $first]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.newer', route('issues.events.show', [$this->issue, $second]))
            );

        $this->actingAs($this->user)
            ->get(route('issues.events.show', [$this->issue, $second]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.older', route('issues.events.show', [$this->issue, $first]))
            );
    }
}
