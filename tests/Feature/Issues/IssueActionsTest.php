<?php

namespace Tests\Feature\Issues;

use App\Enums\IssueActivityType;
use App\Enums\IssueStatus;
use App\Enums\OrganizationRole;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Zustandsaktionen an Fehlern (S6): erledigen, stummschalten, merken,
 * abonnieren, löschen — einzeln und als Sammelaktion.
 *
 * Geprüft wird jeweils beides: dass die Aktion wirkt **und** dass sie im
 * Aktivitätsverlauf steht. Eine Aktion ohne Vermerk ist die häufigste Art, wie
 * ein Verlauf unbrauchbar wird — sie fällt nirgends auf, außer wenn man ihn
 * braucht.
 */
class IssueActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
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
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
            ...$attributes,
        ]);
    }

    public function test_resolving_sets_the_state_and_writes_the_history(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)
            ->from(route('issues.show', $issue))
            ->post(route('issues.actions.store'), [
                'action' => 'resolve',
                'mode' => 'now',
                'issues' => [$issue->id],
            ])
            ->assertRedirect();

        $issue->refresh();

        $this->assertSame(IssueStatus::Resolved, $issue->status);
        $this->assertSame($user->id, $issue->resolved_by_id);
        $this->assertNotNull($issue->resolved_at);

        $activity = IssueActivity::query()->where('issue_id', $issue->id)->sole();

        $this->assertSame(IssueActivityType::Resolved, $activity->type);
        $this->assertSame($user->name, $activity->actor_name);
        $this->assertSame('now', $activity->data['mode'] ?? null);
    }

    /**
     * „In dieser Version" meint die Version, in der der Fehler zuletzt gesehen
     * wurde — nicht die neueste des Projekts.
     */
    public function test_resolving_in_the_current_release_takes_the_release_of_the_issue(): void
    {
        [$user, $project] = $this->context();

        $seen = Release::factory()->for($project)->create(['version' => '1.4.2']);
        $newer = Release::factory()->for($project)->create(['version' => '1.5.0']);

        $issue = $this->issue($project, ['last_release_id' => $seen->id]);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'resolve',
            'mode' => 'current_release',
            'issues' => [$issue->id],
        ]);

        $issue->refresh();

        $this->assertSame($seen->id, $issue->resolved_in_release_id);
        $this->assertNotSame($newer->id, $issue->resolved_in_release_id);
    }

    public function test_resolving_in_the_next_release_marks_the_flag_without_a_release(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'resolve',
            'mode' => 'next_release',
            'issues' => [$issue->id],
        ]);

        $issue->refresh();

        $this->assertTrue($issue->resolved_in_next_release);
        $this->assertNull($issue->resolved_in_release_id);
    }

    /**
     * Die Zusage der Aufgabe: erledigte Fehler verschwinden aus der
     * Standard-Ansicht, bleiben aber auffindbar.
     */
    public function test_resolved_issues_leave_the_default_list_but_stay_findable(): void
    {
        [$user, $project] = $this->context();

        $open = $this->issue($project, ['title' => 'Offen']);
        $done = $this->issue($project, ['title' => 'Erledigt']);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'resolve',
            'mode' => 'now',
            'issues' => [$done->id],
        ]);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'Offen')
            );

        // Über den Zustandsfilter ist er weiterhin da.
        $this->actingAs($user)
            ->get(route('issues.index', ['status' => 'resolved']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'Erledigt')
            );

        $this->assertNotNull(Issue::query()->find($done->id));
        $this->assertSame(IssueStatus::Unresolved, $open->refresh()->status);
    }

    public function test_ignoring_stores_the_condition_and_the_counter_snapshot(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project, ['times_seen' => 40_000, 'users_seen' => 12]);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'ignore',
            'mode' => 'until_count',
            'count' => 100,
            'window' => 60,
            'issues' => [$issue->id],
        ]);

        $issue->refresh();

        $this->assertSame(IssueStatus::Ignored, $issue->status);
        $this->assertSame(100, $issue->ignore_count);
        $this->assertSame(60, $issue->ignore_window_minutes);
        // Der Ausgangsstand ist der Bezugspunkt: ohne ihn wäre die Schwelle in
        // derselben Sekunde erreicht, in der jemand stummgeschaltet hat.
        $this->assertSame(40_000, $issue->ignore_times_seen);
        $this->assertSame(12, $issue->ignore_users_seen);
    }

    public function test_ignoring_rejects_a_threshold_without_a_count(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)
            ->from(route('issues.index'))
            ->post(route('issues.actions.store'), [
                'action' => 'ignore',
                'mode' => 'until_count',
                'issues' => [$issue->id],
            ])
            ->assertSessionHasErrors('count');

        $this->assertSame(IssueStatus::Unresolved, $issue->refresh()->status);
    }

    public function test_bookmark_and_subscription_belong_to_the_viewer(): void
    {
        [$user, $project] = $this->context();
        $other = User::factory()->create();
        $project->organization->setRole($other, OrganizationRole::Member);

        $issue = $this->issue($project);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'bookmark',
            'issues' => [$issue->id],
        ]);

        $this->assertTrue($issue->bookmarkedBy()->whereKey($user->id)->exists());
        $this->assertFalse($issue->bookmarkedBy()->whereKey($other->id)->exists());

        // Zweimal merken ergibt keine zweite Zeile — der eindeutige Index sagt
        // das der Datenbank, und die Aktion muss es nicht nachsehen.
        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'bookmark',
            'issues' => [$issue->id],
        ]);

        $this->assertSame(1, DB::table('issue_bookmarks')->where('issue_id', $issue->id)->count());
    }

    public function test_undo_reverses_the_last_action(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $response = $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'resolve',
            'mode' => 'now',
            'issues' => [$issue->id],
        ]);

        $undo = $response->getSession()->get('undo');

        $this->assertIsArray($undo);
        $this->assertArrayHasKey('token', $undo);
        // Die Seite bekommt nur die Kennmarke — die Kennungen bleiben auf dem
        // Server, sonst könnte man beim Klick bestimmen, was getroffen wird.
        $this->assertArrayNotHasKey('issues', $undo);

        $this->actingAs($user)->post(route('issues.actions.undo'), ['token' => $undo['token']]);

        $this->assertSame(IssueStatus::Unresolved, $issue->refresh()->status);

        // Ein zweiter Klick auf dieselbe Meldung darf nicht die Aktion davor
        // mitnehmen.
        $this->actingAs($user)
            ->post(route('issues.actions.undo'), ['token' => $undo['token']])
            ->assertSessionHas('error');
    }

    public function test_a_stranger_cannot_touch_an_issue(): void
    {
        [, $project] = $this->context();
        $issue = $this->issue($project);

        $stranger = User::factory()->create();
        Organization::factory()->withMember($stranger)->create();

        $this->actingAs($stranger)
            ->from(route('issues.index'))
            ->post(route('issues.actions.store'), [
                'action' => 'resolve',
                'mode' => 'now',
                'issues' => [$issue->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame(IssueStatus::Unresolved, $issue->refresh()->status);
    }

    /**
     * Die Sammelaktion über „alle": gemeint ist genau die Menge, die die Liste
     * zeigt — also mit demselben Zustandsfilter.
     */
    public function test_acting_on_all_matching_uses_the_list_query(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, ['title' => 'A']);
        $this->issue($project, ['title' => 'B']);
        $ignored = $this->issue($project, ['title' => 'C', 'status' => IssueStatus::Ignored]);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'resolve',
            'mode' => 'now',
            'all' => true,
        ]);

        // Zwei offene erledigt, der stummgeschaltete bleibt: er stand nicht in
        // der Liste, auf die sich „alle" bezog.
        $this->assertSame(2, Issue::query()->where('status', IssueStatus::Resolved)->count());
        $this->assertSame(IssueStatus::Ignored, $ignored->refresh()->status);
    }

    public function test_deleting_removes_the_issue_and_leaves_a_trace(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'delete',
            'issues' => [$issue->id],
        ]);

        $this->assertNull(Issue::query()->find($issue->id));

        // Der Vermerk überlebt den Eintrag — ohne Verweis auf ihn, denn den gibt
        // es nicht mehr.
        $activity = IssueActivity::query()->where('project_id', $project->id)->sole();

        $this->assertSame(IssueActivityType::Deleted, $activity->type);
        $this->assertNull($activity->issue_id);
    }

    public function test_an_action_without_a_target_is_rejected(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->from(route('issues.index'))
            ->post(route('issues.actions.store'), ['action' => 'resolve', 'mode' => 'now'])
            ->assertSessionHasErrors('issues');
    }
}
