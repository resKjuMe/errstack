<?php

namespace Tests\Feature\Issues;

use App\Enums\EventLevel;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Tags\TagAggregates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Das Mitschreiben der Merkmale beim Eingang: was in den Zählern landet, wie sie
 * sich unter Wiederholung verhalten und wo die Obergrenze greift.
 */
class TagAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * Die Gruppe je Eintrag — eine, nicht eine je Ereignis.
     *
     * Genau so sieht es im Betrieb aus: alle Meldungen eines Fehlers liegen in
     * derselben Gruppe. Eine je Ereignis wäre nicht nur unrealistisch, sie
     * verstieße auch gegen den eindeutigen Index auf dem Fingerabdruck.
     *
     * @var array<int, EventGroup>
     */
    private array $groups = [];

    /**
     * Ein Ereignis in einem Eintrag — so, wie die Kette es hinterlässt.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function event(Project $project, Issue $issue, array $attributes = []): Event
    {
        $group = $this->groups[$issue->id] ??= EventGroup::factory()
            ->for($project)
            // Ein eigener Fingerabdruck je Eintrag: zwei Gruppen desselben
            // Projekts dürfen nicht denselben tragen.
            ->custom('eintrag-'.$issue->id)
            ->create(['issue_id' => $issue->id]);

        return Event::factory()
            ->for($project)
            ->for(IngestPayload::factory()->for($project), 'payload')
            ->create([
                'event_group_id' => $group->id,
                'occurred_at' => Carbon::now()->subMinutes(5),
                ...$attributes,
            ]);
    }

    /**
     * @return array{Project, Issue}
     */
    private function context(): array
    {
        $project = Project::factory()->create();

        return [$project, Issue::factory()->for($project)->create()];
    }

    public function test_a_counted_event_writes_its_tags_on_both_levels(): void
    {
        [$project, $issue] = $this->context();

        $issue->record($this->event($project, $issue, [
            'environment' => 'production',
            'contexts' => ['browser' => ['name' => 'Chrome', 'version' => '124.0']],
        ]));

        $this->assertDatabaseHas('issue_tags', [
            'issue_id' => $issue->id,
            'project_id' => $project->id,
            'tag_key' => 'browser',
            'tag_value' => 'Chrome 124.0',
            'times_seen' => 1,
        ]);

        $this->assertDatabaseHas('project_tags', [
            'project_id' => $project->id,
            'tag_key' => 'browser',
            'tag_value' => 'Chrome 124.0',
            'times_seen' => 1,
        ]);

        // Der Nenner steht daneben — ohne ihn wäre der Anteil nicht zu rechnen.
        $this->assertDatabaseHas('issue_tag_keys', [
            'issue_id' => $issue->id,
            'tag_key' => 'browser',
            'times_seen' => 1,
            'value_count' => 1,
        ]);
    }

    public function test_two_browsers_share_the_denominator_of_their_tag(): void
    {
        [$project, $issue] = $this->context();

        $issue->record($this->event($project, $issue, [
            'contexts' => ['browser' => ['name' => 'Chrome', 'version' => '124.0']],
        ]));
        $issue->record($this->event($project, $issue, [
            'contexts' => ['browser' => ['name' => 'Chrome', 'version' => '124.0']],
        ]));
        $issue->record($this->event($project, $issue, [
            'contexts' => ['browser' => ['name' => 'Firefox', 'version' => '125']],
        ]));

        $this->assertDatabaseHas('issue_tags', [
            'issue_id' => $issue->id, 'tag_key' => 'browser', 'tag_value' => 'Chrome 124.0', 'times_seen' => 2,
        ]);
        $this->assertDatabaseHas('issue_tags', [
            'issue_id' => $issue->id, 'tag_key' => 'browser', 'tag_value' => 'Firefox 125', 'times_seen' => 1,
        ]);
        $this->assertDatabaseHas('issue_tag_keys', [
            'issue_id' => $issue->id, 'tag_key' => 'browser', 'times_seen' => 3, 'value_count' => 2,
        ]);
    }

    public function test_the_same_event_a_second_time_does_not_double_the_counters(): void
    {
        [$project, $issue] = $this->context();

        $event = $this->event($project, $issue, [
            'contexts' => ['browser' => ['name' => 'Chrome', 'version' => '124.0']],
        ]);

        $this->assertTrue($issue->record($event));
        $this->assertFalse($issue->record($event->fresh()));

        $this->assertDatabaseHas('issue_tags', [
            'issue_id' => $issue->id, 'tag_key' => 'browser', 'times_seen' => 1,
        ]);
    }

    public function test_first_and_last_seen_follow_the_clock_of_the_watched_application(): void
    {
        [$project, $issue] = $this->context();

        $issue->record($this->event($project, $issue, [
            'occurred_at' => Carbon::now()->subHour(),
            'server_name' => 'web-07',
        ]));

        // Nachgereicht: ein SDK, das nach einer Netztrennung seine Warteschlange
        // leert. Der Wert ist älter als der bereits vermerkte — er darf das
        // letzte Auftreten nicht zurückdatieren.
        $issue->record($this->event($project, $issue, [
            'occurred_at' => Carbon::now()->subDay(),
            'server_name' => 'web-07',
        ]));

        $row = DB::table('issue_tags')
            ->where('issue_id', $issue->id)
            ->where('tag_key', 'server_name')
            ->first();

        $this->assertSame(2, (int) $row->times_seen);
        $this->assertSame(
            Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($row->first_seen)->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            Carbon::now()->subHour()->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($row->last_seen)->format('Y-m-d H:i:s'),
        );
    }

    public function test_values_beyond_the_limit_are_not_kept_but_still_counted(): void
    {
        [$project, $issue] = $this->context();

        // Ein Merkmal mit einem Wert je Ereignis — der Fall, für den es die
        // Obergrenze gibt.
        for ($i = 0; $i <= TagAggregates::MAX_VALUES_PER_KEY; $i++) {
            $issue->record($this->event($project, $issue, [
                'level' => EventLevel::Error,
                'tags' => ['auftrag' => 'nr-'.$i],
            ]));
        }

        $values = DB::table('issue_tags')
            ->where('issue_id', $issue->id)
            ->where('tag_key', 'auftrag')
            ->count();

        $this->assertSame(TagAggregates::MAX_VALUES_PER_KEY, $values);

        // Der Nenner zählt weiter: was fehlt, fehlt sichtbar und verteilt sich
        // nicht auf die vorhandenen Werte.
        $this->assertDatabaseHas('issue_tag_keys', [
            'issue_id' => $issue->id,
            'tag_key' => 'auftrag',
            'times_seen' => TagAggregates::MAX_VALUES_PER_KEY + 1,
            'value_count' => TagAggregates::MAX_VALUES_PER_KEY,
        ]);
    }

    public function test_a_second_issue_of_the_same_project_shares_the_project_counter(): void
    {
        [$project, $issue] = $this->context();
        $other = Issue::factory()->for($project)->create();

        $issue->record($this->event($project, $issue, ['server_name' => 'web-07']));
        $other->record($this->event($project, $other, ['server_name' => 'web-07']));

        $this->assertDatabaseHas('issue_tags', [
            'issue_id' => $issue->id, 'tag_key' => 'server_name', 'times_seen' => 1,
        ]);
        $this->assertDatabaseHas('issue_tags', [
            'issue_id' => $other->id, 'tag_key' => 'server_name', 'times_seen' => 1,
        ]);

        // Die Projekt-Ebene ist die Summe — und sie wird mitgeschrieben, nicht
        // gerechnet.
        $this->assertDatabaseHas('project_tags', [
            'project_id' => $project->id, 'tag_key' => 'server_name', 'tag_value' => 'web-07', 'times_seen' => 2,
        ]);
    }
}
