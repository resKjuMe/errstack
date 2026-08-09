<?php

namespace Tests\Feature\Integrations;

use App\Enums\ExternalIssueState;
use App\Enums\IssueActivityType;
use App\Enums\IssueStatus;
use App\Enums\OrganizationRole;
use App\Jobs\FetchReleaseCommits;
use App\Jobs\ProcessIntegrationWebhook;
use App\Models\Integration;
use App\Models\IntegrationWebhookEvent;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Der Eingang für Meldungen von GitHub: Unterschrift, Einmaligkeit,
 * Zustandsabgleich.
 */
class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'hook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.webhook_secret' => self::SECRET]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(string $event, array $payload, string $delivery = 'd-1', ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $delivery,
            'Content-Type' => 'application/json',
        ];

        if ($secret !== null) {
            // Über den **rohen** Rumpf: ein neu serialisiertes JSON ergäbe eine
            // andere Zeichenkette und damit eine andere Unterschrift.
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', (string) $body, $secret);
        }

        return $this->call('POST', '/api/hooks/github', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /**
     * @return array{Organization, Issue, IssueLink}
     */
    private function linked(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $integration = Integration::factory()->for($organization)->create();

        Repository::factory()->for($organization)->create([
            'name' => 'acme/webshop',
            'integration_id' => $integration->id,
        ]);

        $project = Project::factory()->for($organization)->create();
        $issue = Issue::factory()->for($project)->create(['status' => IssueStatus::Unresolved]);

        $link = IssueLink::factory()->for($issue)->create([
            'repository' => 'acme/webshop',
            'number' => 42,
            'integration_id' => $integration->id,
        ]);

        return [$organization, $issue, $link];
    }

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        $this->deliver('issues', ['action' => 'closed'], secret: null)->assertStatus(401);

        $this->assertSame(0, IntegrationWebhookEvent::query()->count());
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        $this->deliver('issues', ['action' => 'closed'], secret: 'falsch')->assertStatus(401);

        $this->assertSame(0, IntegrationWebhookEvent::query()->count());
    }

    public function test_without_a_configured_secret_nothing_is_accepted(): void
    {
        // Ohne Geheimnis nähme der Endpunkt von jedem alles an — und „schließe
        // Ticket 42" ist eine Meldung, die einen Fehler auf erledigt setzt.
        config(['services.github.webhook_secret' => '']);

        $this->deliver('issues', ['action' => 'closed'], secret: null)->assertStatus(401);
    }

    public function test_the_same_delivery_is_processed_once(): void
    {
        Queue::fake();

        $payload = ['action' => 'closed', 'issue' => ['number' => 42, 'state' => 'closed']];

        $this->deliver('issues', $payload)->assertStatus(202);
        $this->deliver('issues', $payload)->assertStatus(202);

        $this->assertSame(1, IntegrationWebhookEvent::query()->count());
        Queue::assertPushed(ProcessIntegrationWebhook::class, 1);
    }

    public function test_a_closed_ticket_resolves_the_issue(): void
    {
        [, $issue, $link] = $this->linked();

        $this->deliver('issues', [
            'action' => 'closed',
            'issue' => ['number' => 42, 'state' => 'closed', 'title' => 'Kasse hängt'],
            'repository' => ['full_name' => 'acme/webshop'],
        ])->assertStatus(202);

        $this->assertSame(IssueStatus::Resolved, $issue->refresh()->status);
        $this->assertSame(ExternalIssueState::Closed, $link->refresh()->state);
        $this->assertSame('Kasse hängt', $link->title);

        // Ohne handelndes Konto: geschlossen hat das jemand in einer anderen
        // Anwendung, und ein Name, den wir hier hinschreiben, wäre geraten.
        $activity = IssueActivity::query()->where('issue_id', $issue->id)->sole();

        $this->assertSame(IssueActivityType::ExternalResolved, $activity->type);
        $this->assertNull($activity->actor_name);
    }

    public function test_a_reopened_ticket_does_not_reopen_the_issue(): void
    {
        [, $issue, $link] = $this->linked();

        $issue->forceFill(['status' => IssueStatus::Resolved])->save();

        $this->deliver('issues', [
            'action' => 'reopened',
            'issue' => ['number' => 42, 'state' => 'open'],
            'repository' => ['full_name' => 'acme/webshop'],
        ])->assertStatus(202);

        // Der Zustand wird nachgeführt, die Entscheidung hier nicht
        // überstimmt: „erledigt" kann auf einem zweiten Ticket beruhen.
        $this->assertSame(ExternalIssueState::Open, $link->refresh()->state);
        $this->assertSame(IssueStatus::Resolved, $issue->refresh()->status);
    }

    public function test_an_event_for_an_unknown_repository_is_still_recorded(): void
    {
        $this->deliver('issues', [
            'action' => 'closed',
            'issue' => ['number' => 1, 'state' => 'closed'],
            'repository' => ['full_name' => 'fremd/unbekannt'],
        ])->assertStatus(202);

        $event = IntegrationWebhookEvent::query()->sole();

        // „Angekommen und passt zu nichts" ist die häufigste Antwort auf
        // „warum passiert nichts?" — und ohne diesen Vermerk nicht von „gar
        // nichts angekommen" zu unterscheiden.
        $this->assertNull($event->organization_id);
        $this->assertNotNull($event->processed_at);
        $this->assertNotNull($event->result);
    }

    public function test_a_push_makes_a_waiting_release_fetch_its_commits(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $integration = Integration::factory()->for($organization)->create();

        Repository::factory()->for($organization)->create([
            'name' => 'acme/webshop',
            'integration_id' => $integration->id,
        ]);

        $project = Project::factory()->for($organization)->create();
        $release = Release::factory()->for($project)->create(['ref' => 'abc123']);

        $this->deliver('push', [
            'after' => 'abc123',
            'commits' => [['id' => 'abc123']],
            'repository' => ['full_name' => 'acme/webshop'],
        ])->assertStatus(202);

        // Der Auftrag läuft erst nach der Antwort: GitHub gibt zehn Sekunden.
        (new ProcessIntegrationWebhook(IntegrationWebhookEvent::query()->sole()->id))->handle();

        Queue::assertPushed(
            FetchReleaseCommits::class,
            fn (FetchReleaseCommits $job): bool => $job->releaseId === $release->id,
        );
    }
}
