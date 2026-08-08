<?php

namespace Tests\Feature\Issues;

use App\Enums\IssueActivityType;
use App\Enums\IssueIgnoreMode;
use App\Enums\IssueStatus;
use App\Enums\NotificationEventType;
use App\Enums\OrganizationRole;
use App\Jobs\DeliverPersonalNotification;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Issues\IssueActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Die Zuständigkeit und die Prüfliste (S7).
 *
 * Geprüft wird beides zusammen, weil es zusammengehört: eine Zuweisung ist die
 * Antwort auf die Frage, die die Prüfliste stellt — und ein Eintrag, der
 * zugewiesen wird und trotzdem in der Prüfliste stehen bleibt, macht sie
 * unbrauchbar.
 */
class IssueAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Project, Organization}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create();

        $user->switchOrganization($organization);

        return [$user, $project, $organization];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Project $project, array $attributes = []): Issue
    {
        // Die Zeitpunkte ausdrücklich in den voreingestellten Zeitraum der
        // Filterleiste gelegt: die Vorlage streut sie über dreißig Tage, und
        // eine Liste, die den Eintrag deshalb nicht zeigt, wäre ein Fehlschlag
        // ohne Bezug zur Zuständigkeit.
        return Issue::factory()->for($project)->create([
            'first_seen' => CarbonImmutable::now()->subHours(6),
            'last_seen' => CarbonImmutable::now()->subHour(),
            ...$attributes,
        ]);
    }

    private function member(Organization $organization, string $name, string $email): User
    {
        $member = User::factory()->create(['name' => $name, 'email' => $email]);

        $organization->setRole($member, OrganizationRole::Member);

        return $member;
    }

    public function test_an_issue_can_be_assigned_to_a_person(): void
    {
        [$user, $project, $organization] = $this->context();
        $colleague = $this->member($organization, 'Anna Beck', 'anna@example.com');
        $issue = $this->issue($project, ['for_review_at' => CarbonImmutable::now()]);

        $this->actingAs($user)
            ->post(route('issues.actions.store'), [
                'action' => 'assign',
                'assignee' => 'anna@example.com',
                'issues' => [$issue->id],
            ])
            ->assertSessionHas('status');

        $issue->refresh();

        $this->assertSame($colleague->id, $issue->assigned_user_id);
        $this->assertNull($issue->assigned_team_id);
        $this->assertSame($user->id, $issue->assigned_by_id);
        $this->assertNotNull($issue->assigned_at);

        // Zuweisen heißt geprüft: der Eintrag verlässt die Prüfliste.
        $this->assertNull($issue->for_review_at);
    }

    public function test_an_issue_can_be_assigned_to_a_team(): void
    {
        [$user, $project, $organization] = $this->context();
        $team = Team::factory()->for($organization)->create(['name' => 'Kasse']);
        $issue = $this->issue($project);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'assign',
            'assignee' => '#Kasse',
            'issues' => [$issue->id],
        ]);

        $issue->refresh();

        $this->assertSame($team->id, $issue->assigned_team_id);
        $this->assertNull($issue->assigned_user_id);
    }

    /**
     * Der Vertrag: höchstens **eine** Zuständigkeit. Wer von einem Team auf eine
     * Person wechselt, hat danach nicht beides.
     */
    public function test_assigning_replaces_the_previous_assignee(): void
    {
        [$user, $project, $organization] = $this->context();
        $team = Team::factory()->for($organization)->create(['name' => 'Kasse']);
        $colleague = $this->member($organization, 'Anna Beck', 'anna@example.com');

        $issue = $this->issue($project, ['assigned_team_id' => $team->id]);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'assign',
            'assignee' => 'anna@example.com',
            'issues' => [$issue->id],
        ]);

        $issue->refresh();

        $this->assertSame($colleague->id, $issue->assigned_user_id);
        $this->assertNull($issue->assigned_team_id);
    }

    public function test_an_assignment_can_be_removed(): void
    {
        [$user, $project, $organization] = $this->context();
        $colleague = $this->member($organization, 'Anna Beck', 'anna@example.com');
        $issue = $this->issue($project, ['assigned_user_id' => $colleague->id]);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'assign',
            'assignee' => 'none',
            'issues' => [$issue->id],
        ]);

        $issue->refresh();

        $this->assertNull($issue->assigned_user_id);
        $this->assertNull($issue->assigned_team_id);

        $this->assertDatabaseHas('issue_activities', [
            'issue_id' => $issue->id,
            'type' => IssueActivityType::Unassigned->value,
        ]);
    }

    public function test_assigning_is_written_to_the_activity_feed(): void
    {
        [$user, $project, $organization] = $this->context();
        $this->member($organization, 'Anna Beck', 'anna@example.com');
        $issue = $this->issue($project);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'assign',
            'assignee' => 'anna@example.com',
            'issues' => [$issue->id],
        ]);

        $activity = IssueActivity::query()
            ->where('issue_id', $issue->id)
            ->where('type', IssueActivityType::Assigned)
            ->firstOrFail();

        // Der **Name** und nicht die Kennung: ein Verlauf wird gelesen.
        $this->assertSame('Anna Beck', $activity->data['assignee'] ?? null);
        $this->assertSame($user->name, $activity->actor_name);
    }

    public function test_a_bulk_assignment_covers_the_whole_selection(): void
    {
        [$user, $project, $organization] = $this->context();
        $colleague = $this->member($organization, 'Anna Beck', 'anna@example.com');

        $issues = collect(range(1, 3))->map(fn (): Issue => $this->issue($project));

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'assign',
            'assignee' => 'anna@example.com',
            'all' => true,
            'projects' => [$project->slug],
        ]);

        foreach ($issues as $issue) {
            $this->assertSame($colleague->id, $issue->refresh()->assigned_user_id);
        }
    }

    public function test_an_unknown_assignee_is_rejected(): void
    {
        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)
            ->post(route('issues.actions.store'), [
                'action' => 'assign',
                'assignee' => 'niemand@example.com',
                'issues' => [$issue->id],
            ])
            ->assertSessionHasErrors('assignee');

        $this->assertNull($issue->refresh()->assigned_user_id);
    }

    public function test_the_assignee_is_notified(): void
    {
        Queue::fake();

        [$user, $project, $organization] = $this->context();
        $colleague = $this->member($organization, 'Anna Beck', 'anna@example.com');
        $issue = $this->issue($project);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'assign',
            'assignee' => 'anna@example.com',
            'issues' => [$issue->id],
        ]);

        Queue::assertPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->user->is($colleague)
                && $job->event === NotificationEventType::Assignment,
        );
    }

    /**
     * Wer selbst zuweist, hört nichts — auch nicht von sich selbst.
     */
    public function test_the_actor_is_not_notified(): void
    {
        Queue::fake();

        [$user, $project] = $this->context();
        $issue = $this->issue($project);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            'action' => 'assign',
            'assignee' => 'me',
            'issues' => [$issue->id],
        ]);

        $this->assertSame($user->id, $issue->refresh()->assigned_user_id);

        Queue::assertNotPushed(DeliverPersonalNotification::class);
    }

    public function test_the_search_finds_issues_by_assignee(): void
    {
        [$user, $project, $organization] = $this->context();
        $colleague = $this->member($organization, 'Anna Beck', 'anna@example.com');

        $mine = $this->issue($project, ['assigned_user_id' => $colleague->id]);
        $other = $this->issue($project);

        $this->actingAs($user)
            ->get(route('issues.index', [
                'projects' => [$project->slug],
                'q' => 'assigned:anna@example.com',
                'status' => 'alle',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.total', 1)
                ->where('issues.data.0.id', $mine->id)
                // Die Zeile trägt den Zuständigen mit — sonst müsste die Liste
                // ihn je Zeile nachladen.
                ->where('issues.data.0.assignee.label', 'Anna Beck')
                // Und der Begriff, der nicht ausgewertet werden konnte, ist
                // keiner: `assigned:` ist seit S7 eine gewöhnliche Spalte.
                ->where('unavailableTerms', [])
            );

        $this->assertNotNull($other->refresh());
    }

    public function test_the_search_finds_unassigned_issues(): void
    {
        [$user, $project, $organization] = $this->context();
        $colleague = $this->member($organization, 'Anna Beck', 'anna@example.com');

        $this->issue($project, ['assigned_user_id' => $colleague->id]);
        $free = $this->issue($project);

        $this->actingAs($user)
            ->get(route('issues.index', [
                'projects' => [$project->slug],
                'q' => 'is:unassigned',
                'status' => 'alle',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.total', 1)
                ->where('issues.data.0.id', $free->id)
            );
    }

    public function test_an_unknown_assignee_in_the_search_is_reported(): void
    {
        [$user, $project] = $this->context();
        $this->issue($project);

        $this->actingAs($user)
            ->get(route('issues.index', [
                'projects' => [$project->slug],
                'q' => 'assigned:wer@example.com',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Eine leere Liste ohne Hinweis wäre die falsche Antwort: sie
                // liest sich wie „hat nichts offen".
                ->where('searchError.message', fn (?string $message): bool => $message !== null)
            );
    }

    public function test_new_issues_land_in_the_review_list(): void
    {
        [$user, $project] = $this->context();

        $fresh = $this->issue($project, ['for_review_at' => CarbonImmutable::now()]);
        $this->issue($project);

        $this->actingAs($user)
            ->get(route('issues.index', [
                'projects' => [$project->slug],
                'q' => 'is:for_review',
                'status' => 'alle',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issues.total', 1)
                ->where('issues.data.0.id', $fresh->id)
                ->where('issues.data.0.forReview', true)
            );
    }

    /**
     * Die Prüfliste leert sich durch Bearbeitung — und zwar durch jede der drei
     * Entscheidungen, nicht nur durch die Zuweisung.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('reviewClearingActions')]
    public function test_the_review_list_empties_through_work(array $payload): void
    {
        [$user, $project, $organization] = $this->context();
        $this->member($organization, 'Anna Beck', 'anna@example.com');

        $issue = $this->issue($project, ['for_review_at' => CarbonImmutable::now()]);

        $this->actingAs($user)->post(route('issues.actions.store'), [
            ...$payload,
            'issues' => [$issue->id],
        ]);

        $this->assertNull($issue->refresh()->for_review_at);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function reviewClearingActions(): array
    {
        return [
            'zuweisen' => [['action' => 'assign', 'assignee' => 'anna@example.com']],
            'erledigen' => [['action' => 'resolve', 'mode' => 'now']],
            'stummschalten' => [['action' => 'ignore', 'mode' => IssueIgnoreMode::Forever->value]],
        ];
    }

    /**
     * Ein Eintrag, der von selbst zurückkehrt, gehört wieder auf die Prüfliste:
     * niemand hat entschieden, dass er wiederkommen darf.
     */
    public function test_an_expired_ignore_puts_the_issue_back_up_for_review(): void
    {
        [, $project] = $this->context();

        $issue = $this->issue($project, [
            'status' => IssueStatus::Ignored,
            'ignored_at' => CarbonImmutable::now()->subHour(),
            'ignore_count' => 10,
            'ignore_times_seen' => 0,
            'ignore_users_seen' => 0,
            'times_seen' => 0,
            'for_review_at' => null,
        ]);

        $this->assertTrue(IssueActions::expireIgnore($issue, 20, 0));

        $issue->refresh();

        $this->assertSame(IssueStatus::Unresolved, $issue->status);
        $this->assertNotNull($issue->for_review_at);
    }

    /**
     * Die Standard-Ansichten sagen, ob sie beantwortbar sind. „Zur Prüfung" und
     * „Mir zugewiesen" sind es seit dieser Aufgabe, „Wieder aufgetreten" seit
     * S8 — die Leiste bietet damit alle drei an.
     */
    public function test_the_views_report_what_is_answerable(): void
    {
        [$user, $project] = $this->context();

        $this->actingAs($user)
            ->get(route('issues.index', ['projects' => [$project->slug], 'q' => '']))
            ->assertInertia(function (AssertableInertia $page): void {
                /** @var list<array{key: string, available: bool}> $rows */
                $rows = $page->toArray()['props']['savedSearches']['views'];

                $available = array_column($rows, 'available', 'key');

                $this->assertTrue($available['for_review']);
                $this->assertTrue($available['assigned']);
                $this->assertTrue($available['regressed']);
            });
    }

    public function test_the_suggestions_offer_people_and_teams(): void
    {
        [$user, , $organization] = $this->context();

        Team::factory()->for($organization)->create(['name' => 'Kasse']);
        $this->member($organization, 'Anna Beck', 'anna@example.com');

        $response = $this->actingAs($user)
            ->getJson(route('issues.assignment.suggest'))
            ->assertOk();

        /** @var list<array{value: string, label: string, kind: string}> $suggestions */
        $suggestions = $response->json('suggestions');

        $values = array_column($suggestions, 'value');

        $this->assertContains('me', $values);
        $this->assertContains('#Kasse', $values);
        $this->assertContains('anna@example.com', $values);
    }

    /**
     * Zugewiesen wird nur, was der Betrachter auch sehen darf — eine geratene
     * Kennung ist ein Aufruf wie jeder andere.
     */
    public function test_a_foreign_issue_cannot_be_assigned(): void
    {
        [$user, , $organization] = $this->context();
        $this->member($organization, 'Anna Beck', 'anna@example.com');

        $foreign = $this->issue(Project::factory()->for(Organization::factory())->create());

        $this->actingAs($user)
            ->post(route('issues.actions.store'), [
                'action' => 'assign',
                'assignee' => 'anna@example.com',
                'issues' => [$foreign->id],
            ])
            ->assertSessionHas('error');

        $this->assertNull($foreign->refresh()->assigned_user_id);
    }
}
