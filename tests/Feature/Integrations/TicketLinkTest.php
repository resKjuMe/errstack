<?php

namespace Tests\Feature\Integrations;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Enums\IssueActivityType;
use App\Enums\OrganizationRole;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aus einem Fehler einen Jira-Vorgang bzw. eine Linear-Aufgabe machen — oder ihn
 * an ein vorhandenes Ticket hängen (X4).
 *
 * Die Datei prüft ausdrücklich auch, **dass beide Anbieter durch dieselbe Tür
 * gehen**: dieselbe Adresse, dieselbe Tabelle, derselbe Verlaufseintrag. Das ist
 * das Abnahmekriterium „auf einer gemeinsamen Ticket-Schnittstelle" — und es
 * wäre ohne Test genau die Sorte Zusage, die beim dritten Anbieter auffällt.
 */
class TicketLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array{User, Organization, Issue, Integration}
     */
    private function context(IntegrationProvider $provider = IntegrationProvider::Jira): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $user->switchOrganization($organization);

        $project = Project::factory()->for($organization)->create();
        $issue = Issue::factory()->for($project)->create();

        $integration = $provider === IntegrationProvider::Jira
            ? Integration::factory()->for($organization)->jira()->create()
            : Integration::factory()->for($organization)->linear()->create();

        return [$user, $organization, $issue, $integration];
    }

    public function test_a_jira_issue_is_created_and_linked(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake([
            'acme.atlassian.net/rest/api/3/issue' => Http::response(['id' => '10042', 'key' => 'OPS-42']),
            'acme.atlassian.net/rest/api/3/issue/OPS-42*' => Http::response([
                'id' => '10042',
                'key' => 'OPS-42',
                'fields' => [
                    'summary' => 'TypeError in Kasse',
                    'status' => ['statusCategory' => ['key' => 'new']],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'jira',
                'repository' => 'OPS',
            ])
            ->assertRedirect();

        $link = IssueLink::query()->sole();

        $this->assertSame(IntegrationProvider::Jira, $link->provider);
        $this->assertSame('OPS', $link->repository);
        $this->assertSame(42, $link->number);
        // Die Kennung des Anbieters — sie ist der Grund für die Spalte: ohne sie
        // wäre das Ticket später nicht zu schließen.
        $this->assertSame('10042', $link->external_id);
        $this->assertSame('OPS-42', $link->reference());
        $this->assertSame('https://acme.atlassian.net/browse/OPS-42', $link->url);
        $this->assertSame(ExternalIssueState::Open, $link->state);
        $this->assertTrue($link->created_remotely);

        $this->assertDatabaseHas('issue_activities', [
            'issue_id' => $issue->id,
            'type' => IssueActivityType::ExternalLinked->value,
        ]);
    }

    /**
     * Der Rumpf ist ein Atlassian-Dokument und kein Markdown: die Fassung 3 der
     * Schnittstelle nimmt nichts anderes.
     */
    public function test_the_jira_description_is_a_document(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake([
            'acme.atlassian.net/rest/api/3/issue' => Http::response(['id' => '1', 'key' => 'OPS-1']),
            'acme.atlassian.net/rest/api/3/issue/OPS-1*' => Http::response([
                'id' => '1',
                'key' => 'OPS-1',
                'fields' => ['summary' => 'x', 'status' => ['statusCategory' => ['key' => 'new']]],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'jira',
                'repository' => 'OPS',
            ])
            ->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/rest/api/3/issue')) {
                return false;
            }

            return ($request['fields']['description']['type'] ?? null) === 'doc'
                && ($request['fields']['issuetype']['name'] ?? null) === 'Task';
        });
    }

    /**
     * Die Vorbelegung der Anbindung landet im neuen Vorgang — das ist der Zweck
     * der Einstellung.
     */
    public function test_the_defaults_of_the_integration_are_used(): void
    {
        [$user, $organization, $issue, $integration] = $this->context();

        $integration->forceFill(['settings' => [
            'default_type' => 'Bug',
            'default_priority' => 'High',
            'default_assignee' => 'acc-9',
        ]])->save();

        Http::fake([
            'acme.atlassian.net/rest/api/3/issue' => Http::response(['id' => '1', 'key' => 'OPS-1']),
            'acme.atlassian.net/rest/api/3/issue/OPS-1*' => Http::response([
                'id' => '1',
                'key' => 'OPS-1',
                'fields' => ['summary' => 'x', 'status' => ['statusCategory' => ['key' => 'new']]],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'jira',
                'repository' => 'OPS',
            ])
            ->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/rest/api/3/issue')) {
                return false;
            }

            return ($request['fields']['issuetype']['name'] ?? null) === 'Bug'
                && ($request['fields']['priority']['name'] ?? null) === 'High'
                && ($request['fields']['assignee']['id'] ?? null) === 'acc-9';
        });
    }

    /**
     * Ein Vorgang, der schon in der Kategorie `done` steht, wird als geschlossen
     * verknüpft — über die **Kategorie** und nicht über den Namen des Zustands.
     * Der Name ist je Arbeitsablauf frei („Abgenommen"), die Kategorie legt
     * Atlassian fest.
     */
    public function test_an_existing_jira_issue_is_linked_with_its_state(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake([
            'acme.atlassian.net/rest/api/3/issue/OPS-7*' => Http::response([
                'id' => '10007',
                'key' => 'OPS-7',
                'fields' => [
                    'summary' => 'Läuft schon',
                    'status' => ['name' => 'Abgenommen', 'statusCategory' => ['key' => 'done']],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'jira',
                'repository' => 'OPS',
                // Die Kennung ganz, wie sie drüben steht — genau so kommt sie aus
                // der Zwischenablage.
                'number' => 'OPS-7',
            ])
            ->assertRedirect();

        $link = IssueLink::query()->sole();

        $this->assertSame(7, $link->number);
        $this->assertSame(ExternalIssueState::Closed, $link->state);
        $this->assertFalse($link->created_remotely);
    }

    /**
     * `ENG-42` im Projekt `OPS` ist keine Nummer mit Vorsilbe, sondern ein
     * anderes Ticket. Es stillschweigend als `OPS-42` zu lesen wäre die Sorte
     * Hilfsbereitschaft, die eine Verknüpfung auf den falschen Vorgang legt.
     */
    public function test_a_key_from_another_project_is_a_validation_error(): void
    {
        [$user, $organization, $issue] = $this->context();

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'jira',
                'repository' => 'OPS',
                'number' => 'ENG-42',
            ])
            ->assertSessionHasErrors('number');

        $this->assertSame(0, IssueLink::query()->count());
    }

    public function test_a_missing_ticket_is_a_validation_error(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake([
            'acme.atlassian.net/*' => Http::response(['errorMessages' => ['Issue does not exist']], 404),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'jira',
                'repository' => 'OPS',
                'number' => 4711,
            ])
            ->assertSessionHasErrors('number');

        $this->assertSame(0, IssueLink::query()->count());
    }

    public function test_a_linear_task_is_created_and_linked(): void
    {
        [$user, $organization, $issue] = $this->context(IntegrationProvider::Linear);

        Http::fake([
            'api.linear.app/*' => Http::sequence()
                // Erst das Team: angelegt wird über seine Kennung, nicht über den
                // Schlüssel aus der Auswahlliste.
                ->push(['data' => ['teams' => ['nodes' => [['id' => 'team-1', 'key' => 'ENG', 'name' => 'Engineering']]]]])
                ->push(['data' => ['issueCreate' => [
                    'success' => true,
                    'issue' => [
                        'id' => 'iss-uuid',
                        'identifier' => 'ENG-9',
                        'number' => 9,
                        'title' => 'TypeError in Kasse',
                        'url' => 'https://linear.app/acme/issue/ENG-9',
                        'state' => ['type' => 'unstarted'],
                        'team' => ['key' => 'ENG'],
                    ],
                ]]]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'linear',
                'repository' => 'ENG',
            ])
            ->assertRedirect();

        $link = IssueLink::query()->sole();

        // Dieselbe Tabelle, dieselben Spalten, dieselbe Schreibweise der Kennung
        // wie bei Jira — das ist die gemeinsame Schnittstelle, von hier aus
        // gesehen.
        $this->assertSame(IntegrationProvider::Linear, $link->provider);
        $this->assertSame('ENG', $link->repository);
        $this->assertSame(9, $link->number);
        $this->assertSame('iss-uuid', $link->external_id);
        $this->assertSame('ENG-9', $link->reference());
    }

    /**
     * Linear meldet einen abgelehnten Wunsch mit `success: false` und **ohne**
     * `errors`. Ohne diese Prüfung entstünde eine Verknüpfung auf ein Ticket, das
     * es nicht gibt.
     */
    public function test_linear_reporting_no_success_does_not_create_a_link(): void
    {
        [$user, $organization, $issue] = $this->context(IntegrationProvider::Linear);

        Http::fake([
            'api.linear.app/*' => Http::sequence()
                ->push(['data' => ['teams' => ['nodes' => [['id' => 'team-1', 'key' => 'ENG', 'name' => 'Engineering']]]]])
                ->push(['data' => ['issueCreate' => ['success' => false, 'issue' => null]]]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'linear',
                'repository' => 'ENG',
            ])
            ->assertSessionHasErrors('repository');

        $this->assertSame(0, IssueLink::query()->count());
    }

    /**
     * Ein Fehler kann mit mehreren Tickets verknüpft sein — auch mit welchen aus
     * verschiedenen Systemen (Contract der Aufgabe).
     */
    public function test_an_issue_can_be_linked_to_tickets_of_several_providers(): void
    {
        [$user, $organization, $issue] = $this->context();

        Integration::factory()->for($organization)->linear()->create();

        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create();
        IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Linear, 'ENG', 9)->create();

        $this->assertSame(
            ['OPS-42', 'ENG-9'],
            $issue->links()->orderBy('id')->get()->map(fn (IssueLink $link): string => $link->reference())->all(),
        );
    }

    /**
     * Das Lösen fragt niemanden drüben — es löscht eine Zeile hier und schreibt
     * einen Vermerk. Das Ticket bleibt stehen und offen.
     */
    public function test_unlinking_leaves_the_ticket_alone(): void
    {
        [$user, $organization, $issue] = $this->context();

        $link = IssueLink::factory()->for($issue)->ticket(IntegrationProvider::Jira, 'OPS', 42)->create();

        $this->actingAs($user)
            ->delete(route('issues.links.destroy', [$organization, $issue, $link]))
            ->assertRedirect();

        $this->assertSame(0, IssueLink::query()->count());
        $this->assertDatabaseHas('issue_activities', [
            'issue_id' => $issue->id,
            'type' => IssueActivityType::ExternalUnlinked->value,
        ]);

        // Der Vermerk trägt die Kennung als Text und nicht als Verweis: ein
        // Verlauf sagt, was damals galt.
        $activity = IssueActivity::query()
            ->where('type', IssueActivityType::ExternalUnlinked)
            ->sole();

        $this->assertSame('OPS-42', $activity->data['reference']);
    }

    public function test_without_a_connection_nothing_is_linked(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $user->switchOrganization($organization);

        $project = Project::factory()->for($organization)->create();
        $issue = Issue::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'provider' => 'jira',
                'repository' => 'OPS',
            ])
            ->assertRedirect();

        $this->assertSame(0, IssueLink::query()->count());
    }
}
