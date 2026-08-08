<?php

namespace Tests\Feature\Issues;

use App\Enums\NotificationEventType;
use App\Enums\OrganizationRole;
use App\Jobs\DeliverPersonalNotification;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\IssueCommentMention;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Kommentare an einem Fehler (S10): schreiben, ändern, löschen — und die
 * Nennungen, die dabei jemanden erreichen.
 *
 * Geprüft wird nicht nur, dass der Kommentar dasteht, sondern jedes Mal auch,
 * **wer davon erfährt**. Das ist der Teil, der still ausfällt: ein Kommentar
 * ohne Benachrichtigung sieht genauso aus wie einer mit.
 */
class IssueCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));

        Queue::fake();
    }

    /**
     * @return array{User, Organization, Project, Issue}
     */
    private function context(): array
    {
        $user = User::factory()->create(['name' => 'Chris Wagner']);
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create();
        $issue = Issue::factory()->for($project)->create();

        $user->switchOrganization($organization);

        return [$user, $organization, $project, $issue];
    }

    public function test_ein_mitglied_schreibt_einen_kommentar(): void
    {
        [$user, , , $issue] = $this->context();

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => '  Das lag am Zeitlimit.  '])
            ->assertRedirect();

        $comment = IssueComment::query()->firstOrFail();

        $this->assertSame('Das lag am Zeitlimit.', $comment->body);
        $this->assertSame($user->id, $comment->user_id);
        // Der Name zum Zeitpunkt des Schreibens steht mit im Kommentar: er
        // überlebt eine spätere Umbenennung und das Löschen des Kontos.
        $this->assertSame('Chris Wagner', $comment->author_name);
        $this->assertSame($issue->project_id, $comment->project_id);
        $this->assertNull($comment->edited_at);
    }

    public function test_ein_leerer_kommentar_wird_abgewiesen(): void
    {
        [$user, , , $issue] = $this->context();

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => "   \n  "])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, IssueComment::query()->count());
    }

    public function test_wer_die_organisation_nicht_kennt_darf_nicht_kommentieren(): void
    {
        [, , , $issue] = $this->context();

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('issues.comments.store', $issue), ['body' => 'Hallo?'])
            ->assertForbidden();

        $this->assertSame(0, IssueComment::query()->count());
    }

    public function test_eine_nennung_benachrichtigt_die_genannte_person(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna Beck']);
        $organization->setRole($anna, OrganizationRole::Member);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), [
                'body' => '@Anna Beck kannst du dir das ansehen?',
            ])
            ->assertRedirect();

        $mention = IssueCommentMention::query()->firstOrFail();

        $this->assertSame($anna->id, $mention->user_id);
        $this->assertNull($mention->team_id);
        // Die Beschriftung ist der Wortlaut aus dem Kommentar: daran erkennt
        // der Server die Stelle beim Anzeigen wieder.
        $this->assertSame('Anna Beck', $mention->label);

        Queue::assertPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->event === NotificationEventType::Mention,
        );
    }

    public function test_der_schreibende_bekommt_keine_nachricht_ueber_sich_selbst(): void
    {
        [$user, , , $issue] = $this->context();

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => '@Chris Wagner notiert.'])
            ->assertRedirect();

        // Die Nennung wird festgehalten — gemeint war sie ja —, aber niemand
        // wird über die eigene Nachricht benachrichtigt.
        $this->assertSame(1, IssueCommentMention::query()->count());

        Queue::assertNotPushed(DeliverPersonalNotification::class);
    }

    public function test_eine_team_nennung_erreicht_die_mitglieder(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna Beck']);
        $ben = User::factory()->create(['name' => 'Ben Roth']);
        $organization->setRole($anna, OrganizationRole::Member);
        $organization->setRole($ben, OrganizationRole::Member);

        $team = Team::factory()->for($organization)->create(['name' => 'Kasse']);
        $team->members()->attach([$anna->id, $ben->id, $user->id]);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => '@Kasse bitte prüfen'])
            ->assertRedirect();

        $mention = IssueCommentMention::query()->firstOrFail();

        $this->assertSame($team->id, $mention->team_id);
        $this->assertNull($mention->user_id);

        // Zwei Mitglieder, nicht drei: der Schreibende ist selbst im Team.
        Queue::assertPushed(DeliverPersonalNotification::class, 2);
    }

    public function test_wer_nicht_in_der_organisation_ist_wird_nicht_genannt(): void
    {
        [$user, , , $issue] = $this->context();

        User::factory()->create(['name' => 'Fremde Person']);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => '@Fremde Person schau mal'])
            ->assertRedirect();

        $this->assertSame(0, IssueCommentMention::query()->count());

        Queue::assertNotPushed(DeliverPersonalNotification::class);
    }

    public function test_eine_adresse_ist_keine_nennung(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna']);
        $organization->setRole($anna, OrganizationRole::Member);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), [
                'body' => 'Gemeldet von post@anna.example',
            ])
            ->assertRedirect();

        $this->assertSame(0, IssueCommentMention::query()->count());
    }

    public function test_beim_bearbeiten_wird_nur_der_neu_genannte_benachrichtigt(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna Beck']);
        $ben = User::factory()->create(['name' => 'Ben Roth']);
        $organization->setRole($anna, OrganizationRole::Member);
        $organization->setRole($ben, OrganizationRole::Member);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => '@Anna Beck bitte prüfen'])
            ->assertRedirect();

        Queue::assertPushed(DeliverPersonalNotification::class, 1);

        $comment = IssueComment::query()->firstOrFail();

        $this->actingAs($user)
            ->patch(route('issues.comments.update', [$issue, $comment]), [
                'body' => '@Anna Beck bitte prüfen, sonst @Ben Roth',
            ])
            ->assertRedirect();

        // Zwei insgesamt, nicht drei: Anna stand schon vorher drin und bekommt
        // die Nachricht nicht ein zweites Mal.
        Queue::assertPushed(DeliverPersonalNotification::class, 2);

        $this->assertSame(2, $comment->fresh()->mentions()->count());
        $this->assertNotNull($comment->fresh()->edited_at);
    }

    public function test_eine_entfernte_nennung_verschwindet(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna Beck']);
        $organization->setRole($anna, OrganizationRole::Member);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => '@Anna Beck bitte prüfen'])
            ->assertRedirect();

        $comment = IssueComment::query()->firstOrFail();

        $this->actingAs($user)
            ->patch(route('issues.comments.update', [$issue, $comment]), ['body' => 'Erledigt sich.'])
            ->assertRedirect();

        $this->assertSame(0, IssueCommentMention::query()->count());
    }

    public function test_ein_unveraenderter_rumpf_gilt_nicht_als_bearbeitet(): void
    {
        [$user, , , $issue] = $this->context();

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => 'Unverändert.'])
            ->assertRedirect();

        $comment = IssueComment::query()->firstOrFail();

        $this->actingAs($user)
            ->patch(route('issues.comments.update', [$issue, $comment]), ['body' => 'Unverändert.'])
            ->assertRedirect();

        $this->assertNull($comment->fresh()->edited_at);
    }

    public function test_ein_fremder_kommentar_laesst_sich_nicht_aendern(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna Beck']);
        $organization->setRole($anna, OrganizationRole::Owner);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => 'Meins.'])
            ->assertRedirect();

        $comment = IssueComment::query()->firstOrFail();

        // Auch die Verwaltung nicht: sie darf löschen, aber niemandem Worte in
        // den Mund legen.
        $this->actingAs($anna)
            ->patch(route('issues.comments.update', [$issue, $comment]), ['body' => 'Deins.'])
            ->assertForbidden();

        $this->assertSame('Meins.', $comment->fresh()->body);
    }

    public function test_die_verwaltung_darf_einen_fremden_kommentar_loeschen(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $admin = User::factory()->create();
        $organization->setRole($admin, OrganizationRole::Admin);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => 'Ein Zugangstoken: geheim'])
            ->assertRedirect();

        $comment = IssueComment::query()->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('issues.comments.destroy', [$issue, $comment]))
            ->assertRedirect();

        $this->assertSame(0, IssueComment::query()->count());
    }

    public function test_ein_mitglied_darf_fremde_kommentare_nicht_loeschen(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $other = User::factory()->create();
        $organization->setRole($other, OrganizationRole::Member);

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => 'Meins.'])
            ->assertRedirect();

        $comment = IssueComment::query()->firstOrFail();

        $this->actingAs($other)
            ->delete(route('issues.comments.destroy', [$issue, $comment]))
            ->assertForbidden();

        $this->assertSame(1, IssueComment::query()->count());
    }

    public function test_ein_kommentar_eines_anderen_fehlers_wird_abgewiesen(): void
    {
        [$user, , $project, $issue] = $this->context();

        $other = Issue::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('issues.comments.store', $issue), ['body' => 'Meins.'])
            ->assertRedirect();

        $comment = IssueComment::query()->firstOrFail();

        // Beide Kennungen stehen in der Adresszeile; eine vertauschte Zeile darf
        // keinen fremden Kommentar unter fremdem Fehler ändern.
        $this->actingAs($user)
            ->patch(route('issues.comments.update', [$other, $comment]), ['body' => 'Anders.'])
            ->assertNotFound();
    }

    public function test_die_vorschlaege_zeigen_mitglieder_und_teams(): void
    {
        [$user, $organization, , $issue] = $this->context();

        $anna = User::factory()->create(['name' => 'Anna Beck']);
        $organization->setRole($anna, OrganizationRole::Member);
        Team::factory()->for($organization)->create(['name' => 'Anna und Kollegen']);

        User::factory()->create(['name' => 'Anna Fremd']);

        $response = $this->actingAs($user)
            ->getJson(route('issues.comments.suggest', $issue).'?q=Anna');

        $names = array_column($response->json('suggestions'), 'name');

        $this->assertContains('Anna Beck', $names);
        $this->assertContains('Anna und Kollegen', $names);
        // Wer nicht in der Organisation ist, steht nicht in der Liste: eine
        // Nennung außerhalb wäre eine Auskunft über fremde Projekte.
        $this->assertNotContains('Anna Fremd', $names);
    }
}
