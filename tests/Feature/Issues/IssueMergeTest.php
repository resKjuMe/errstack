<?php

namespace Tests\Feature\Issues;

use App\Enums\CountPeriod;
use App\Enums\EventLevel;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Models\IssueCount;
use App\Models\IssueTag;
use App\Models\IssueTagKey;
use App\Models\IssueUser;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Ingest\Grouping\Fingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Fehler von Hand zusammenführen und wieder auftrennen (S9).
 *
 * Die Zusage, an der alles hängt: **verlustfrei**. Zusammengeführt wird über
 * einen Verweis, und deshalb prüfen diese Tests nicht nur, dass die Zahlen des
 * Ergebnisses stimmen, sondern auch, dass sie nach dem Auftrennen wieder die
 * alten sind — und dass keine Meldung dabei die Seite gewechselt hat.
 */
class IssueMergeTest extends TestCase
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
    private function issue(Project $project, string $title, array $attributes = []): Issue
    {
        return Issue::factory()->for($project)->create([
            'title' => $title,
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHours(1),
            ...$attributes,
        ]);
    }

    /**
     * Eine Gruppe samt Meldung an einem Eintrag — das, was beim Zusammenführen
     * mitkommen muss.
     */
    private function group(Issue $issue, string $fingerprint): EventGroup
    {
        $group = EventGroup::factory()
            ->for($issue->project)
            ->for($issue)
            ->create(['fingerprint' => $fingerprint]);

        Event::factory()
            ->for($issue->project)
            ->create([
                'event_group_id' => $group->id,
                'occurred_at' => $issue->last_seen,
            ]);

        return $group;
    }

    private function affectedUser(Issue $issue, string $key): void
    {
        IssueUser::query()->insert([
            'issue_id' => $issue->id,
            'user_key' => $key,
            'first_seen' => Carbon::now()->subHours(3),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function test_merging_folds_the_figures_into_the_biggest_entry(): void
    {
        [$user, , $project] = $this->context();

        $big = $this->issue($project, 'RuntimeException: Zahlung', [
            'times_seen' => 900,
            'users_seen' => 2,
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHours(2),
        ]);

        $small = $this->issue($project, 'RuntimeException: Zahlung (Zeile verschoben)', [
            'times_seen' => 100,
            'users_seen' => 2,
            'first_seen' => Carbon::now()->subDays(3),
            'last_seen' => Carbon::now()->subMinutes(30),
            'level' => EventLevel::Fatal,
        ]);

        // Ein Betroffener überschneidet sich: die Zahl der Betroffenen ist
        // deshalb drei und nicht vier.
        $this->affectedUser($big, str_repeat('a', 32));
        $this->affectedUser($big, str_repeat('b', 32));
        $this->affectedUser($small, str_repeat('b', 32));
        $this->affectedUser($small, str_repeat('c', 32));

        $this->actingAs($user)
            ->post(route('issues.merge.store'), ['issues' => [$small->id, $big->id]])
            // Der Kopf ist der häufigere Eintrag, unabhängig von der Reihenfolge
            // der Auswahl.
            ->assertRedirect(route('issues.show', $big));

        $big->refresh();
        $small->refresh();

        $this->assertSame(1000, $big->times_seen);
        $this->assertSame(3, $big->users_seen);
        $this->assertTrue($big->first_seen->equalTo($small->first_seen), 'Die Spanne beginnt beim älteren Eintrag.');
        $this->assertTrue($big->last_seen->equalTo(Carbon::now()->subMinutes(30)), 'Die Spanne endet beim jüngeren Auftreten.');
        // Der Grad folgt dem jüngsten Auftreten — an ihm hängen die Alarmregeln.
        $this->assertSame(EventLevel::Fatal, $big->level);

        // Der beigetretene Eintrag behält seine eigenen Zahlen: genau sie sind
        // beim Auftrennen wieder abzuziehen.
        $this->assertSame($big->id, $small->merged_into_id);
        $this->assertSame(100, $small->times_seen);
    }

    public function test_a_subgroup_no_longer_stands_in_the_list(): void
    {
        [$user, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 900]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 100]);

        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$head->id, $member->id],
        ]);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.id', $head->id)
                ->where('issues.data.0.timesSeen', 1000)
                // Die Zeile sagt, dass ihre Zahlen für zwei Fingerabdrücke
                // gelten — sonst wären sie in der Liste unerklärlich.
                ->where('issues.data.0.mergedCount', 1)
            );
    }

    public function test_the_detail_page_shows_the_subgroups(): void
    {
        [$user, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 900]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 100]);

        $this->group($head, str_repeat('1', 64));
        $this->group($member, str_repeat('2', 64));

        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$head->id, $member->id],
        ]);

        $this->actingAs($user)
            ->get(route('issues.show', $head))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Show')
                ->has('issue.merged', 1)
                ->where('issue.merged.0.id', $member->id)
                ->where('issue.merged.0.timesSeen', 100)
                ->where('issue.merged.0.fingerprints', [str_repeat('2', 12)])
                ->where('issue.mergedInto', null)
            );

        // Und umgekehrt: die Untergruppe bleibt aufrufbar und sagt, wozu sie
        // gehört.
        $this->actingAs($user)
            ->get(route('issues.show', $member))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.mergedInto.id', $head->id)
                ->has('issue.merged', 0)
            );
    }

    public function test_the_head_shows_the_events_of_its_subgroups(): void
    {
        [$user, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 900]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 100]);

        $this->group($head, str_repeat('1', 64));
        $group = $this->group($member, str_repeat('2', 64));

        /** @var Event $event */
        $event = $group->events()->sole();

        // Vor dem Zusammenführen gehört die Meldung nicht zum Kopf.
        $this->actingAs($user)
            ->get(route('issues.events.show', [$head, $event]))
            ->assertNotFound();

        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$head->id, $member->id],
        ]);

        $this->actingAs($user)
            ->get(route('issues.events.show', [$head, $event]))
            ->assertOk();
    }

    public function test_subsequent_events_count_towards_the_head(): void
    {
        [, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 900]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 100]);

        $group = $this->group($member, str_repeat('2', 64));

        $member->forceFill(['merged_into_id' => $head->id])->save();

        /** @var Event $event */
        $event = $group->events()->sole();

        // Die Kette fragt für die Gruppe nach dem Eintrag und bekommt den Kopf:
        // sonst liefe die Zählung an einem Eintrag auf, den niemand mehr sieht.
        $this->assertSame($head->id, Issue::forGroup($group->fresh(), $event)->id);
    }

    public function test_splitting_off_restores_the_entry_and_the_figures(): void
    {
        [$user, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 900, 'users_seen' => 2]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 100, 'users_seen' => 2]);

        $this->affectedUser($head, str_repeat('a', 32));
        $this->affectedUser($head, str_repeat('b', 32));
        $this->affectedUser($member, str_repeat('b', 32));
        $this->affectedUser($member, str_repeat('c', 32));

        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$head->id, $member->id],
        ]);

        $this->assertSame(3, $head->fresh()->users_seen);

        $this->actingAs($user)
            ->delete(route('issues.merge.destroy', $member))
            ->assertRedirect();

        $head->refresh();
        $member->refresh();

        $this->assertNull($member->merged_into_id);
        $this->assertSame(100, $member->times_seen, 'Die Untergruppe bringt ihre eigenen Zahlen mit.');
        $this->assertSame(900, $head->times_seen, 'Am Kopf ist genau der Beitrag wieder abgezogen.');
        $this->assertSame(2, $head->users_seen, 'Die Betroffenen sind neu ausgezählt.');

        // Beide stehen wieder für sich in der Liste.
        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('issues.data', 2));
    }

    public function test_the_series_of_the_head_includes_its_subgroups(): void
    {
        [$user, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 6]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 4]);

        // Dasselbe Fenster für beide: der Kopf zeichnet die Summe und nicht zwei
        // Balken nebeneinander.
        $window = Carbon::now()->subHours(2)->startOfHour();

        IssueCount::query()->insert([
            [
                'issue_id' => $head->id,
                'period' => CountPeriod::Hour->value,
                'window_start' => $window,
                'event_count' => 6,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'issue_id' => $member->id,
                'period' => CountPeriod::Hour->value,
                'window_start' => $window,
                'event_count' => 4,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);

        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$head->id, $member->id],
        ]);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                // Ein Balken mit zehn und nicht zwei mit sechs und vier.
                ->where('issues.data.0.series', fn (Collection $series): bool => $series->sum() === 10
                    && $series->max() === 10)
            );
    }

    public function test_the_tags_of_the_head_include_its_subgroups(): void
    {
        [$user, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 6]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 4]);

        foreach ([[$head, 6], [$member, 4]] as [$issue, $count]) {
            IssueTagKey::query()->insert([
                'issue_id' => $issue->id,
                'project_id' => $project->id,
                'tag_key' => 'browser',
                'times_seen' => $count,
                'value_count' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            IssueTag::query()->insert([
                'issue_id' => $issue->id,
                'project_id' => $project->id,
                'tag_key' => 'browser',
                'tag_value' => 'Chrome 124',
                'times_seen' => $count,
                'first_seen' => Carbon::now()->subHours(3),
                'last_seen' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$head->id, $member->id],
        ]);

        $this->actingAs($user)
            ->get(route('issues.tags.index', $head))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('facets', 1)
                ->where('facets.0.total', 10)
                ->where('facets.0.values.0.count', 10)
            );
    }

    public function test_issues_of_different_projects_cannot_be_merged(): void
    {
        [$user, $organization, $project] = $this->context();

        $other = Project::factory()->for($organization)->create(['slug' => 'app']);

        $here = $this->issue($project, 'Hier');
        $there = $this->issue($other, 'Dort');

        $this->actingAs($user)
            ->post(route('issues.merge.store'), ['issues' => [$here->id, $there->id]])
            ->assertSessionHasErrors('issues');

        $this->assertNull($here->fresh()->merged_into_id);
        $this->assertNull($there->fresh()->merged_into_id);
    }

    public function test_an_entry_that_is_already_a_subgroup_cannot_be_merged(): void
    {
        [$user, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 900]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 100]);
        $third = $this->issue($project, 'Dritter', ['times_seen' => 50]);

        $member->forceFill(['merged_into_id' => $head->id])->save();

        $this->actingAs($user)
            ->post(route('issues.merge.store'), ['issues' => [$member->id, $third->id]])
            ->assertSessionHasErrors('issues');

        $this->assertSame($head->id, $member->fresh()->merged_into_id);
        $this->assertNull($third->fresh()->merged_into_id);
    }

    public function test_a_single_issue_is_not_a_merge(): void
    {
        [$user, , $project] = $this->context();

        $issue = $this->issue($project, 'Allein');

        $this->actingAs($user)
            ->post(route('issues.merge.store'), ['issues' => [$issue->id]])
            ->assertSessionHasErrors('issues');
    }

    public function test_merging_an_entry_with_subgroups_keeps_one_level(): void
    {
        [$user, , $project] = $this->context();

        $small = $this->issue($project, 'Klein', ['times_seen' => 10]);
        $middle = $this->issue($project, 'Mittel', ['times_seen' => 100]);
        $big = $this->issue($project, 'Groß', ['times_seen' => 1000]);

        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$small->id, $middle->id],
        ]);

        // „Mittel" ist jetzt ein Kopf mit einer Untergruppe und tritt selbst bei.
        $this->actingAs($user)->post(route('issues.merge.store'), [
            'issues' => [$middle->id, $big->id],
        ]);

        $small->refresh();
        $middle->refresh();
        $big->refresh();

        // Keine Kette: „Klein" hängt unmittelbar am neuen Kopf.
        $this->assertSame($big->id, $small->merged_into_id);
        $this->assertSame($big->id, $middle->merged_into_id);

        // Und die Häufigkeit steht genau einmal in der Summe: „Mittel" gibt
        // seinen geerbten Anteil ab, weil „Klein" ihn nun selbst beiträgt.
        $this->assertSame(1110, $big->times_seen);
        $this->assertSame(100, $middle->times_seen);
        $this->assertSame(10, $small->times_seen);

        // Und das Auftrennen führt zurück: erst „Mittel", dann „Klein".
        $this->actingAs($user)->delete(route('issues.merge.destroy', $middle));
        $this->actingAs($user)->delete(route('issues.merge.destroy', $small));

        $this->assertSame(1000, $big->fresh()->times_seen);
    }

    public function test_splitting_off_an_entry_that_is_not_a_subgroup_is_a_404(): void
    {
        [$user, , $project] = $this->context();

        $issue = $this->issue($project, 'Steht für sich');

        $this->actingAs($user)
            ->delete(route('issues.merge.destroy', $issue))
            ->assertNotFound();
    }

    public function test_an_outsider_cannot_merge(): void
    {
        [, , $project] = $this->context();

        $outsider = User::factory()->create();

        $first = $this->issue($project, 'Erster', ['times_seen' => 10]);
        $second = $this->issue($project, 'Zweiter', ['times_seen' => 5]);

        $this->actingAs($outsider)
            ->post(route('issues.merge.store'), ['issues' => [$first->id, $second->id]])
            ->assertForbidden();

        $this->assertNull($first->fresh()->merged_into_id);
    }

    public function test_the_fingerprint_of_a_subgroup_still_finds_its_own_group(): void
    {
        [, , $project] = $this->context();

        $head = $this->issue($project, 'Kopf', ['times_seen' => 900]);
        $member = $this->issue($project, 'Untergruppe', ['times_seen' => 100]);

        $group = $this->group($member, str_repeat('2', 64));

        $member->forceFill(['merged_into_id' => $head->id])->save();

        // Das Zusammenführen rührt die Gruppierung nicht an: derselbe
        // Fingerabdruck findet dieselbe Gruppe, und die zeigt weiter auf ihren
        // eigenen Eintrag. Genau das macht das Auftrennen verlustfrei.
        $found = EventGroup::forFingerprint($project->id, new Fingerprint(
            hash: str_repeat('2', 64),
            source: $group->source,
            values: [],
        ));

        $this->assertSame($group->id, $found->id);
        $this->assertSame($member->id, $found->issue_id);
    }
}
