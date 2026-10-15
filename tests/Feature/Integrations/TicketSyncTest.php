<?php

namespace Tests\Feature\Integrations;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Enums\IssueActivityType;
use App\Enums\IssueResolveMode;
use App\Enums\IssueStatus;
use App\Jobs\SyncTicketState;
use App\Models\Integration;
use App\Models\IntegrationWebhookEvent;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Issues\IssueActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Statusabgleich in beiden Richtungen (X4) — das Abnahmekriterium dieser
 * Aufgabe.
 *
 * Die drei Zusagen, die hier geprüft werden, sind alle drei welche, deren Bruch
 * im Betrieb still bliebe:
 *
 *   1. Ein geschlossenes Ticket erledigt den Fehler — und ein wieder geöffnetes
 *      öffnet ihn **nicht** von selbst wieder.
 *   2. Ein hier erledigter Fehler schließt sein Ticket.
 *   3. Beide Richtungen sind einzeln abschaltbar, und ein abgeschalteter
 *      Abgleich tut nichts — er meldet aber, dass er nichts getan hat.
 */
class TicketSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array{Organization, Issue, Integration}
     */
    private function context(IntegrationProvider $provider = IntegrationProvider::Jira): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $issue = Issue::factory()->for($project)->create(['status' => IssueStatus::Unresolved]);

        $integration = $provider === IntegrationProvider::Jira
            ? Integration::factory()->for($organization)->jira()->create()
            : Integration::factory()->for($organization)->linear()->create();

        return [$organization, $issue, $integration];
    }

    private function webhookUrl(Integration $integration): string
    {
        return route('webhooks.tickets', [
            'provider' => $integration->provider->value,
            'token' => $integration->webhookToken(),
        ]);
    }

    // ----------------------------------------------------------------- eingehend

    public function test_a_closed_jira_issue_resolves_the_error(): void
    {
        [, $issue, $integration] = $this->context();

        $link = IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create([
            'integration_id' => $integration->id,
        ]);

        $this->postJson($this->webhookUrl($integration), [
            'timestamp' => 1_760_000_000_000,
            'webhookEvent' => 'jira:issue_updated',
            'issue_event_type_name' => 'issue_generic',
            'issue' => [
                'id' => '10042',
                'key' => 'OPS-42',
                'fields' => [
                    'summary' => 'Behoben',
                    'project' => ['key' => 'OPS'],
                    'status' => ['name' => 'Abgenommen', 'statusCategory' => ['key' => 'done']],
                ],
            ],
        ])->assertAccepted();

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
        $this->assertSame(ExternalIssueState::Closed, $link->fresh()->state);
        $this->assertSame('Behoben', $link->fresh()->title);

        // Ohne handelndes Konto: geschlossen hat das jemand in einer anderen
        // Anwendung, und ein Name, den wir hier hinschreiben könnten, wäre
        // geraten.
        $this->assertNull($issue->fresh()->resolved_by_id);

        $this->assertDatabaseHas('issue_activities', [
            'issue_id' => $issue->id,
            'type' => IssueActivityType::ExternalResolved->value,
        ]);
    }

    public function test_a_closed_linear_task_resolves_the_error(): void
    {
        [, $issue, $integration] = $this->context(IntegrationProvider::Linear);

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Linear, 'ENG', 9)->create([
            'integration_id' => $integration->id,
        ]);

        $this->postJson($this->webhookUrl($integration), [
            'action' => 'update',
            'type' => 'Issue',
            'data' => [
                'id' => 'iss-uuid',
                'identifier' => 'ENG-9',
                'number' => 9,
                'title' => 'Behoben',
                'team' => ['key' => 'ENG'],
                'state' => ['type' => 'completed'],
            ],
        ])->assertAccepted();

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
    }

    /**
     * Ein wieder geöffnetes Ticket öffnet den Fehler nicht von selbst: „erledigt"
     * kann hier auf einem zweiten Ticket beruhen, auf einer Auslieferung oder
     * schlicht darauf, dass jemand es entschieden hat.
     */
    public function test_a_reopened_ticket_does_not_reopen_the_error(): void
    {
        [, $issue, $integration] = $this->context();

        $issue->forceFill(['status' => IssueStatus::Resolved, 'resolved_at' => now()])->save();

        $link = IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->closed()->create([
            'integration_id' => $integration->id,
        ]);

        $this->postJson($this->webhookUrl($integration), [
            'timestamp' => 1_760_000_000_001,
            'webhookEvent' => 'jira:issue_updated',
            'issue' => [
                'id' => '10042',
                'key' => 'OPS-42',
                'fields' => [
                    'summary' => 'Doch nicht',
                    'project' => ['key' => 'OPS'],
                    'status' => ['statusCategory' => ['key' => 'indeterminate']],
                ],
            ],
        ])->assertAccepted();

        // Der Zustand der Verknüpfung folgt …
        $this->assertSame(ExternalIssueState::Open, $link->fresh()->state);
        // … die Entscheidung hier bleibt stehen.
        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
    }

    public function test_the_inbound_direction_can_be_switched_off(): void
    {
        [, $issue, $integration] = $this->context();

        $integration->forceFill(['settings' => ['sync_inbound' => false]])->save();

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create([
            'integration_id' => $integration->id,
        ]);

        $this->postJson($this->webhookUrl($integration), [
            'timestamp' => 1_760_000_000_002,
            'webhookEvent' => 'jira:issue_updated',
            'issue' => [
                'key' => 'OPS-42',
                'fields' => [
                    'project' => ['key' => 'OPS'],
                    'status' => ['statusCategory' => ['key' => 'done']],
                ],
            ],
        ])->assertAccepted();

        $this->assertSame(IssueStatus::Unresolved, $issue->fresh()->status);

        // Die Meldung wird trotzdem festgehalten, und der Grund steht am
        // Ereignis: „ich habe den Abgleich abgeschaltet und wundere mich" soll
        // hier zu klären sein und nicht erst in den Einstellungen.
        $event = IntegrationWebhookEvent::query()->sole();

        $this->assertNotNull($event->processed_at);
        $this->assertSame(__('integrations.webhook.results.inbound_off'), $event->result);
    }

    public function test_a_wrong_secret_in_the_address_is_rejected(): void
    {
        [, , $integration] = $this->context();

        $this->postJson(route('webhooks.tickets', ['provider' => 'jira', 'token' => 'falsch']), [
            'webhookEvent' => 'jira:issue_updated',
        ])->assertUnauthorized();

        // Nichts festgehalten: ohne gültige Adresse wird auch kein Eingangsbuch
        // geschrieben, das sich mit Meldungen fluten ließe.
        $this->assertSame(0, IntegrationWebhookEvent::query()->count());

        // Und das Geheimnis der echten Anbindung gilt weiter.
        $this->assertNotNull(Integration::byWebhookToken(IntegrationProvider::Jira, $integration->webhookToken()));
    }

    /**
     * Jira wiederholt eine Zustellung mit demselben Zeitstempel. Sie ein zweites
     * Mal auszuwerten hieße, einen von Hand wieder geöffneten Fehler erneut zu
     * erledigen.
     */
    public function test_the_same_delivery_is_only_processed_once(): void
    {
        [, $issue, $integration] = $this->context();

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create([
            'integration_id' => $integration->id,
        ]);

        $payload = [
            'timestamp' => 1_760_000_000_003,
            'webhookEvent' => 'jira:issue_updated',
            'issue' => [
                'id' => '10042',
                'key' => 'OPS-42',
                'fields' => ['project' => ['key' => 'OPS'], 'status' => ['statusCategory' => ['key' => 'done']]],
            ],
        ];

        $this->postJson($this->webhookUrl($integration), $payload)->assertAccepted();
        $this->postJson($this->webhookUrl($integration), $payload)
            ->assertAccepted()
            ->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, IntegrationWebhookEvent::query()->count());
    }

    /**
     * Ein Ereignis, das kein Ticket betrifft, wird festgehalten und nicht
     * ausgewertet — sonst erledigt ein Kommentar-Ereignis mit eingebettetem
     * Vorgang einen Fehler.
     */
    public function test_an_unrelated_event_is_recorded_and_ignored(): void
    {
        [, $issue, $integration] = $this->context(IntegrationProvider::Linear);

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Linear, 'ENG', 9)->create([
            'integration_id' => $integration->id,
        ]);

        $this->postJson($this->webhookUrl($integration), [
            'action' => 'create',
            'type' => 'Comment',
            'data' => ['id' => 'c1', 'issue' => ['identifier' => 'ENG-9']],
        ])->assertAccepted();

        $this->assertSame(IssueStatus::Unresolved, $issue->fresh()->status);
        $this->assertSame(1, IntegrationWebhookEvent::query()->count());
    }

    // ------------------------------------------------------------------ ausgehend

    public function test_resolving_an_error_closes_its_jira_issue(): void
    {
        [, $issue, $integration] = $this->context();

        $link = IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create([
            'integration_id' => $integration->id,
        ]);

        Http::fake([
            'acme.atlassian.net/rest/api/3/issue/OPS-42/transitions' => Http::response([
                'transitions' => [
                    ['id' => '11', 'name' => 'In Arbeit', 'to' => ['statusCategory' => ['key' => 'indeterminate']]],
                    ['id' => '31', 'name' => 'Abgenommen', 'to' => ['statusCategory' => ['key' => 'done']]],
                ],
            ]),
        ]);

        (new IssueActions)->resolve(Issue::query()->whereKey($issue->id), IssueResolveMode::Now);

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);

        // Der Übergang wird gesucht und nicht geraten: welcher wohin führt,
        // entscheidet der Arbeitsablauf des Projekts.
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/transitions')
                && ($request['transition']['id'] ?? null) === '31';
        });

        // Der Zustand der Verknüpfung wird hier **nicht** vorweggenommen: er
        // kommt mit der Meldung, die der Anbieter gleich zurückschickt.
        $this->assertSame(ExternalIssueState::Open, $link->fresh()->state);
    }

    public function test_reopening_an_error_reopens_its_linear_task(): void
    {
        [, $issue, $integration] = $this->context(IntegrationProvider::Linear);

        $issue->forceFill(['status' => IssueStatus::Resolved, 'resolved_at' => now()])->save();

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Linear, 'ENG', 9)->closed()->create([
            'integration_id' => $integration->id,
        ]);

        Http::fake([
            'api.linear.app/*' => Http::sequence()
                ->push(['data' => ['teams' => ['nodes' => [['states' => ['nodes' => [
                    ['id' => 'st-2', 'name' => 'Später', 'type' => 'unstarted', 'position' => 2],
                    ['id' => 'st-1', 'name' => 'Offen', 'type' => 'unstarted', 'position' => 1],
                ]]]]]]])
                ->push(['data' => ['issueUpdate' => ['success' => true]]]),
        ]);

        (new IssueActions)->unresolve(Issue::query()->whereKey($issue->id));

        // Der Zustand mit der niedrigsten Sortierposition — Teams haben oft
        // mehrere derselben Art.
        Http::assertSent(function (Request $request): bool {
            return ($request['variables']['stateId'] ?? null) === 'st-1';
        });
    }

    public function test_the_outbound_direction_can_be_switched_off(): void
    {
        [, $issue, $integration] = $this->context();

        $integration->forceFill(['settings' => ['sync_outbound' => false]])->save();

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create([
            'integration_id' => $integration->id,
        ]);

        // Ein Auffang-`fake()` und keine Erwartung: es schaltet die Aufzeichnung
        // ein, ohne die `assertNothingSent()` unten trivial wahr wäre.
        Http::fake();

        (new IssueActions)->resolve(Issue::query()->whereKey($issue->id), IssueResolveMode::Now);

        $this->assertSame(IssueStatus::Resolved, $issue->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * Der Regelfall ist eine Organisation **ohne** Ticket-System. Dann soll das
     * Erledigen von 12.480 Fehlern keinen Auftrag in die Schlange legen, der
     * nichts findet.
     */
    public function test_without_a_ticket_link_no_job_is_queued(): void
    {
        Queue::fake();

        [, $issue] = $this->context();

        (new IssueActions)->resolve(Issue::query()->whereKey($issue->id), IssueResolveMode::Now);

        Queue::assertNotPushed(SyncTicketState::class);
    }

    /**
     * Zwischen dem Klick und dem Auftrag liegen unter Umständen Minuten. Wird der
     * Fehler in ihnen wieder geöffnet, darf das Ticket nicht mehr geschlossen
     * werden.
     */
    public function test_a_stale_job_does_nothing(): void
    {
        [, $issue, $integration] = $this->context();

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create([
            'integration_id' => $integration->id,
        ]);

        Http::fake();

        // Der Fehler ist offen; der Auftrag stammt aus einem „erledigt" von
        // vorhin.
        (new SyncTicketState([$issue->id], resolved: true))->handle();

        Http::assertNothingSent();
    }

    /**
     * Ein Ticket, das sich nicht schließen lässt, hält die anderen nicht auf.
     */
    public function test_one_failing_ticket_does_not_stop_the_others(): void
    {
        [, $issue, $integration] = $this->context();

        $second = Issue::factory()->for($issue->project)->create(['status' => IssueStatus::Unresolved]);

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 1)->create([
            'integration_id' => $integration->id,
        ]);
        IssueLink::factory()->for($second)->ticket(IntegrationProvider::Jira, 'OPS', 2)->create([
            'integration_id' => $integration->id,
        ]);

        Http::fake([
            // Kein Übergang nach „erledigt" — eine Auskunft über den
            // Arbeitsablauf, keine Störung.
            'acme.atlassian.net/rest/api/3/issue/OPS-1/transitions' => Http::response(['transitions' => []]),
            'acme.atlassian.net/rest/api/3/issue/OPS-2/transitions' => Http::response([
                'transitions' => [['id' => '31', 'to' => ['statusCategory' => ['key' => 'done']]]],
            ]),
        ]);

        Issue::query()->whereIn('id', [$issue->id, $second->id])->update(['status' => IssueStatus::Resolved]);

        (new SyncTicketState([$issue->id, $second->id], resolved: true))->handle();

        // Der zweite ist trotzdem durchgekommen.
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST' && str_contains($request->url(), 'OPS-2/transitions');
        });
    }

    /**
     * Eine Verknüpfung, deren Anbindung gelöst wurde, bleibt lesbar und wird
     * nicht abgeglichen.
     */
    public function test_a_link_without_an_integration_is_not_synced(): void
    {
        [, $issue] = $this->context();

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create([
            'integration_id' => null,
        ]);

        Issue::query()->whereKey($issue->id)->update(['status' => IssueStatus::Resolved]);

        Http::fake();

        (new SyncTicketState([$issue->id], resolved: true))->handle();

        Http::assertNothingSent();
    }
}
