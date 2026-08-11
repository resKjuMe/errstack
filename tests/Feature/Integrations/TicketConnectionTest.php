<?php

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\OrganizationRole;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ein Ticket-System verbinden (X4).
 *
 * Der Kern dieser Datei ist eine Zusage: **das Token wird geprüft, bevor es
 * gespeichert wird.** Ohne sie entstünde eine Anbindung, die in der Oberfläche
 * „verbunden" heißt und bei jedem Aufruf scheitert — und der Fehler fiele erst
 * jemandem auf, der Wochen später ein Ticket anlegen will.
 */
class TicketConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein Aufruf, den kein `Http::fake()` abdeckt, geht sonst **wirklich**
        // hinaus — in der CI gegen api.linear.app, und das Ergebnis ist ein
        // `401` mitten in einem Test, der von Linear nichts wissen will.
        Http::preventStrayRequests();
    }

    /**
     * @return array{User, Organization}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $user->switchOrganization($organization);

        return [$user, $organization];
    }

    public function test_jira_is_connected_with_a_verified_token(): void
    {
        [$user, $organization] = $this->context();

        Http::fake([
            'acme.atlassian.net/rest/api/3/myself' => Http::response([
                'accountId' => '5f8a1b',
                'displayName' => 'Christian Mietze',
                'emailAddress' => 'ops@acme.test',
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('organizations.integrations.tickets.store', [$organization, 'jira']), [
                'base_url' => 'https://acme.atlassian.net/',
                'email' => 'ops@acme.test',
                'token' => 'atl-token',
            ])
            ->assertRedirect();

        $integration = Integration::query()->sole();

        $this->assertSame(IntegrationProvider::Jira, $integration->provider);
        $this->assertSame('Christian Mietze', $integration->account);
        $this->assertSame('5f8a1b', $integration->external_id);
        $this->assertSame(IntegrationStatus::Connected, $integration->status);
        $this->assertSame($user->id, $integration->connected_by_id);

        // Der Schrägstrich am Ende ist weg: er wandert sonst in jede
        // zusammengesetzte Adresse und ergibt `…net//rest/api/3/…`.
        $this->assertSame('https://acme.atlassian.net', $integration->credential('base_url'));
        $this->assertSame('atl-token', $integration->token());

        // Das Geheimnis der Rückadresse entsteht beim Verbinden — ohne es wäre
        // der eingehende Abgleich nicht erreichbar, und niemand käme auf die
        // Idee, es anzufordern.
        $this->assertNotNull($integration->webhookToken());
        $this->assertSame(
            Integration::hashWebhookToken($integration->webhookToken()),
            $integration->webhook_token_hash,
        );
    }

    public function test_a_rejected_token_is_not_stored(): void
    {
        [$user, $organization] = $this->context();

        Http::fake([
            'acme.atlassian.net/*' => Http::response(['errorMessages' => ['Client must be authenticated']], 401),
        ]);

        $this->actingAs($user)
            ->post(route('organizations.integrations.tickets.store', [$organization, 'jira']), [
                'base_url' => 'https://acme.atlassian.net',
                'email' => 'ops@acme.test',
                'token' => 'falsch',
            ])
            ->assertSessionHasErrors('token');

        $this->assertSame(0, Integration::query()->count());
    }

    /**
     * Ein Tippfehler beim Erneuern darf die funktionierende Anbindung nicht
     * ersetzen.
     */
    public function test_a_failed_reconnect_leaves_the_existing_integration_alone(): void
    {
        [$user, $organization] = $this->context();

        $existing = Integration::factory()->for($organization)->jira()->create();

        Http::fake([
            'acme.atlassian.net/*' => Http::response(['errorMessages' => ['Unauthorized']], 401),
        ]);

        $this->actingAs($user)
            ->post(route('organizations.integrations.tickets.store', [$organization, 'jira']), [
                'base_url' => 'https://acme.atlassian.net',
                'email' => 'ops@acme.test',
                'token' => 'falsch',
            ])
            ->assertSessionHasErrors('token');

        $this->assertSame($existing->token(), $existing->fresh()->token());
        $this->assertSame(IntegrationStatus::Connected, $existing->fresh()->status);
    }

    public function test_linear_needs_only_a_key(): void
    {
        [$user, $organization] = $this->context();

        Http::fake([
            'api.linear.app/*' => Http::response([
                'data' => ['viewer' => ['id' => 'usr_1', 'name' => 'Christian Mietze']],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('organizations.integrations.tickets.store', [$organization, 'linear']), [
                'token' => 'lin_api_key',
            ])
            ->assertRedirect();

        $integration = Integration::query()->sole();

        $this->assertSame(IntegrationProvider::Linear, $integration->provider);
        $this->assertSame('usr_1', $integration->external_id);
        $this->assertNull($integration->credential('base_url'));
    }

    /**
     * GraphQL antwortet auf eine gescheiterte Abfrage mit `200 OK` und einer
     * Liste `errors`. Wer nur auf den Status sieht, speichert einen ungültigen
     * Schlüssel als „verbunden".
     */
    public function test_a_graphql_error_with_status_200_is_a_failure(): void
    {
        [$user, $organization] = $this->context();

        Http::fake([
            'api.linear.app/*' => Http::response([
                'errors' => [[
                    'message' => 'Authentication required',
                    'extensions' => ['code' => 'AUTHENTICATION_ERROR'],
                ]],
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('organizations.integrations.tickets.store', [$organization, 'linear']), [
                'token' => 'falsch',
            ])
            ->assertSessionHasErrors('token');

        $this->assertSame(0, Integration::query()->count());
    }

    public function test_the_sync_directions_are_switchable_on_their_own(): void
    {
        [$user, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->jira()->create();

        $this->assertTrue($integration->syncsInbound());
        $this->assertTrue($integration->syncsOutbound());

        $this->actingAs($user)
            // `patchJson`, damit die Schalter als echte Wahrheitswerte ankommen —
            // so schickt Inertia sie auch.
            ->patchJson(route('organizations.integrations.tickets.update', [$organization, $integration]), [
                'sync_inbound' => true,
                'sync_outbound' => false,
                'default_project' => 'OPS',
                'default_type' => 'Bug',
            ])
            ->assertRedirect();

        $integration->refresh();

        $this->assertTrue($integration->syncsInbound());
        $this->assertFalse($integration->syncsOutbound());
        $this->assertSame('OPS', $integration->setting('default_project'));
        $this->assertSame('Bug', $integration->setting('default_type'));

        // Ein leeres Feld ist „nicht vorbelegt" und nicht „vorbelegt mit
        // nichts": ein leeres `assignee` wäre bei Jira ein Prüffehler.
        $this->assertNull($integration->setting('default_assignee'));
    }

    public function test_the_callback_address_can_be_renewed(): void
    {
        [$user, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->linear()->create();
        $before = $integration->webhookToken();

        $this->actingAs($user)
            ->post(route('organizations.integrations.tickets.rotate', [$organization, $integration]))
            ->assertRedirect();

        $integration->refresh();

        $this->assertNotSame($before, $integration->webhookToken());
        $this->assertSame(
            Integration::hashWebhookToken($integration->webhookToken()),
            $integration->webhook_token_hash,
        );

        // Und die alte Adresse antwortet nicht mehr — das ist der ganze Zweck.
        $this->assertNull(Integration::byWebhookToken(IntegrationProvider::Linear, $before));
    }

    public function test_a_member_without_rights_cannot_connect(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Member)->create();
        $user->switchOrganization($organization);

        $this->actingAs($user)
            ->post(route('organizations.integrations.tickets.store', [$organization, 'linear']), [
                'token' => 'lin_api_key',
            ])
            ->assertForbidden();

        $this->assertSame(0, Integration::query()->count());
    }

    public function test_an_unknown_provider_is_not_found(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post('/organisationen/'.$organization->slug.'/anbindungen/tickets/trello', ['token' => 'x'])
            ->assertNotFound();
    }
}
