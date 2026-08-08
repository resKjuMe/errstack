<?php

namespace Tests\Feature\Issues;

use App\Enums\IssueActivityType;
use App\Enums\OrganizationRole;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueComment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Issues\IssueActivityFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Zeitleiste eines Fehlers (S10): Vermerke und Kommentare in **einer**
 * Liste, chronologisch und blätterbar.
 *
 * Die beiden Dinge liegen in getrennten Tabellen, weil sie gegensätzliche
 * Zusagen haben — Vermerke sind unveränderlich, Kommentare nicht. Dass sie
 * trotzdem als ein Faden gelesen werden, ist genau das, was hier geprüft wird:
 * eine nach Uhrzeit richtig verschränkte Liste, und keine zwei Listen
 * untereinander.
 */
class IssueActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));

        // Hier geht es um die Leiste, nicht um den Versand: die Nennung in
        // einem der Fälle soll nicht nebenbei eine Mail schreiben.
        Queue::fake();
    }

    /**
     * @return array{User, Project, Issue}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $project = Project::factory()->for($organization)->create();
        $issue = Issue::factory()->for($project)->create();

        $user->switchOrganization($organization);

        return [$user, $project, $issue];
    }

    private function activity(Issue $issue, string $at): IssueActivity
    {
        return IssueActivity::factory()->create([
            'issue_id' => $issue->id,
            'project_id' => $issue->project_id,
            'type' => IssueActivityType::Unresolved,
            'actor_name' => 'Anna Beck',
            'created_at' => Carbon::parse($at),
        ]);
    }

    private function comment(Issue $issue, User $author, string $body, string $at): IssueComment
    {
        return IssueComment::factory()->create([
            'issue_id' => $issue->id,
            'project_id' => $issue->project_id,
            'user_id' => $author->id,
            'author_name' => $author->name,
            'body' => $body,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    public function test_vermerke_und_kommentare_stehen_nach_zeit_verschraenkt(): void
    {
        [$user, , $issue] = $this->context();

        $this->activity($issue, '2026-03-10 09:00:00');
        $this->comment($issue, $user, 'Zwischenruf', '2026-03-10 10:00:00');
        $this->activity($issue, '2026-03-10 11:00:00');

        $entries = IssueActivityFeed::forIssue($issue, $user)->items();

        // Neueste zuerst — wie im Änderungsprotokoll: „was ist zuletzt
        // passiert?" ist die häufigere Frage.
        $this->assertSame(
            ['activity', 'comment', 'activity'],
            array_column($entries, 'kind'),
        );
    }

    public function test_ein_kommentar_kommt_in_abschnitten_mit_hervorgehobener_nennung(): void
    {
        [$user, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna Beck']);
        $issue->project->organization->setRole($anna, OrganizationRole::Member);

        $this->actingAs($user)->post(route('issues.comments.store', $issue), [
            'body' => 'Hallo @Anna Beck, bitte prüfen.',
        ])->assertRedirect();

        $entry = IssueActivityFeed::forIssue($issue, $user)->items()[0];

        $this->assertSame('comment', $entry['kind']);
        $this->assertSame(
            [
                ['type' => 'text', 'value' => 'Hallo '],
                ['type' => 'mention', 'value' => '@Anna Beck'],
                ['type' => 'text', 'value' => ', bitte prüfen.'],
            ],
            $entry['segments'],
        );
    }

    public function test_die_leiste_blaettert(): void
    {
        [$user, , $issue] = $this->context();

        // Eine Seite voll plus zwei: die zweite Seite muss es geben, und sie
        // muss die ältesten beiden tragen.
        for ($at = 0; $at < IssueActivityFeed::PER_PAGE + 2; $at++) {
            $this->activity($issue, Carbon::parse('2026-03-10 08:00:00')->addMinutes($at)->toDateTimeString());
        }

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity.data', IssueActivityFeed::PER_PAGE)
                ->where('activity.total', IssueActivityFeed::PER_PAGE + 2)
            );

        // Die Seitenzahl steht unter einem eigenen Namen in der Adresszeile:
        // die Detailseite blättert schon durch die Meldungen, und eine
        // gemeinsame `page` würde beide zugleich weiterschalten.
        $this->actingAs($user)
            ->get(route('issues.show', $issue).'?'.IssueActivityFeed::PAGE_NAME.'=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity.data', 2)
                ->where('activity.current_page', 2)
            );
    }

    public function test_die_detailseite_liefert_die_leiste_und_die_schreibrechte(): void
    {
        [$user, , $issue] = $this->context();

        $this->comment($issue, $user, 'Ein Wort dazu', '2026-03-10 10:00:00');

        $this->actingAs($user)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('issues/Show')
                ->has('activity.data', 1)
                ->where('activity.data.0.kind', 'comment')
                // Die Rechte kommen mit dem Eintrag: die Oberfläche soll keine
                // Schaltfläche zeigen, die beim Klick abgewiesen wird.
                ->where('activity.data.0.canEdit', true)
                ->where('comments.canWrite', true)
            );
    }

    public function test_wer_nur_zusieht_bekommt_keine_bearbeiten_rechte(): void
    {
        [$user, , $issue] = $this->context();

        $other = User::factory()->create();
        $issue->project->organization->setRole($other, OrganizationRole::Member);

        $this->comment($issue, $user, 'Meins', '2026-03-10 10:00:00');

        $other->switchOrganization($issue->project->organization);

        $this->actingAs($other)
            ->get(route('issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activity.data.0.canEdit', false)
                ->where('activity.data.0.canDelete', false)
                // Kommentieren darf trotzdem jedes Mitglied.
                ->where('comments.canWrite', true)
            );
    }
}
