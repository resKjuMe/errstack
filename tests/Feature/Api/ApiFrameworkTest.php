<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Enums\OrganizationRole;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Der Rahmen der öffentlichen Schnittstelle unter /api/0/: Anmeldung per Token,
 * Geltungsbereiche, einheitliche Antwort- und Fehlerform, Blätterung, Sortierung
 * und Ratenbegrenzung.
 */
class ApiFrameworkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein Token samt Klartext-Wert, wie ihn ein Aufrufer mitschicken würde.
     *
     * @param  list<ApiScope>  $scopes
     */
    private function bearer(
        Organization $organization,
        array $scopes,
        ?User $user = null,
        ?Carbon $expiresAt = null,
    ): string {
        $token = ApiToken::issue(
            tokenable: $user ?? $organization,
            organization: $organization,
            createdBy: $user,
            name: 'Test '.uniqid(),
            scopes: $scopes,
            expiresAt: $expiresAt,
        );

        return $token->plainTextToken;
    }

    public function test_without_a_token_the_answer_is_401_in_the_uniform_error_format(): void
    {
        $this->getJson('/api/0/organizations')
            ->assertStatus(401)
            ->assertExactJson([
                'message' => 'Nicht angemeldet: es fehlt ein gültiges API-Token.',
                'errors' => [],
            ]);
    }

    public function test_an_unknown_token_does_not_authenticate(): void
    {
        $this->withToken('1|voellig-erfunden')
            ->getJson('/api/0/organizations')
            ->assertStatus(401);
    }

    public function test_an_expired_token_does_not_authenticate(): void
    {
        $organization = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead], expiresAt: Carbon::now()->subMinute());

        $this->withToken($bearer)
            ->getJson('/api/0/organizations')
            ->assertStatus(401);
    }

    public function test_an_open_browser_session_is_no_substitute_for_a_token(): void
    {
        $user = User::factory()->create();
        Organization::factory()->withMember($user)->create();

        // Die Schnittstelle ist nur über Tokens zu erreichen — sonst käme man
        // ohne geprüfte Geltungsbereiche an die Daten.
        $this->actingAs($user)
            ->getJson('/api/0/organizations')
            ->assertStatus(401);
    }

    public function test_a_token_reads_the_example_endpoint(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Viewer)->create(['name' => 'Acme']);
        $bearer = $this->bearer($organization, [ApiScope::OrgRead], $user);

        $this->withToken($bearer)
            ->getJson('/api/0/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $organization->slug)
            ->assertJsonPath('data.0.name', 'Acme')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_writing_with_a_read_only_token_is_403(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead], $user);

        $this->withToken($bearer)
            ->patchJson('/api/0/organizations/'.$organization->slug, ['name' => 'Neu'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Dem Token fehlt der Geltungsbereich „org:write“.');

        $this->assertSame($organization->name, $organization->refresh()->name);
    }

    public function test_a_write_scope_covers_reading(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgWrite], $user);

        // `org:write` schließt `org:read` ein — sonst müsste jeder Aufrufer beide
        // Bereiche einzeln anfordern.
        $this->withToken($bearer)
            ->getJson('/api/0/organizations/'.$organization->slug)
            ->assertOk();

        $this->withToken($bearer)
            ->patchJson('/api/0/organizations/'.$organization->slug, ['name' => 'Umbenannt'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Umbenannt');
    }

    public function test_the_role_limits_a_personal_token_even_afterwards(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Admin)->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgWrite], $user);

        $this->withToken($bearer)
            ->patchJson('/api/0/organizations/'.$organization->slug, ['name' => 'Erlaubt'])
            ->assertOk();

        // Herabgestuft: dasselbe Token darf nun nicht mehr schreiben, ohne dass
        // es dafür widerrufen werden müsste.
        $organization->setRole($user, OrganizationRole::Viewer);

        $this->withToken($bearer)
            ->patchJson('/api/0/organizations/'.$organization->slug, ['name' => 'Verboten'])
            ->assertStatus(403);
    }

    public function test_a_personal_token_stops_working_after_leaving_the_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead], $user);

        $organization->memberships()->where('user_id', $user->id)->delete();

        $this->withToken($bearer)
            ->getJson('/api/0/organizations')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Das Konto gehört dieser Organisation nicht mehr an.');
    }

    public function test_an_organization_wide_token_works_without_an_account(): void
    {
        $organization = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead]);

        $this->withToken($bearer)
            ->getJson('/api/0/')
            ->assertOk()
            ->assertJsonPath('data.version', '0')
            ->assertJsonPath('data.token.kind', 'organization')
            ->assertJsonPath('data.actor', null);
    }

    public function test_a_token_does_not_reach_another_organization(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead]);

        // 404 und nicht 403: dass es diese Organisation gibt, ist selbst schon
        // eine Auskunft.
        $this->withToken($bearer)
            ->getJson('/api/0/organizations/'.$foreign->slug)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Nicht gefunden.');
    }

    public function test_the_last_use_is_recorded(): void
    {
        $organization = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead]);

        $this->assertNull(ApiToken::query()->firstOrFail()->last_used_at);

        $this->withToken($bearer)->getJson('/api/0/')->assertOk();

        $this->assertNotNull(ApiToken::query()->firstOrFail()->last_used_at);
    }

    public function test_pagination_and_sorting_come_from_the_query_string(): void
    {
        $organization = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead]);

        $this->withToken($bearer)
            ->getJson('/api/0/organizations?per_page=1&page=1&sort=-name')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        // Zweite Seite: es gibt nur eine Organisation je Token, also leer.
        $this->withToken($bearer)
            ->getJson('/api/0/organizations?page=2')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_bad_pagination_or_sorting_is_422_with_field_errors(): void
    {
        $organization = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead]);

        $this->withToken($bearer)
            ->getJson('/api/0/organizations?per_page=5000')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['per_page']]);

        $this->withToken($bearer)
            ->getJson('/api/0/organizations?sort=geheime_spalte')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['sort']]);
    }

    public function test_a_failed_validation_keeps_the_uniform_error_format(): void
    {
        $organization = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgWrite]);

        $this->withToken($bearer)
            ->patchJson('/api/0/organizations/'.$organization->slug, ['name' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['name']]);
    }

    public function test_an_unknown_address_answers_in_the_error_format(): void
    {
        $this->getJson('/api/0/gibt-es-nicht')
            ->assertStatus(404)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_the_rate_limit_answers_429_with_retry_after(): void
    {
        config(['api.rate_limit.max_attempts' => 2]);

        $organization = Organization::factory()->create();
        $bearer = $this->bearer($organization, [ApiScope::OrgRead]);

        $this->withToken($bearer)->getJson('/api/0/')->assertOk();
        $this->withToken($bearer)->getJson('/api/0/')->assertOk();

        $this->withToken($bearer)
            ->getJson('/api/0/')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'errors']);
    }
}
