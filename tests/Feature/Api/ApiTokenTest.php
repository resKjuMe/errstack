<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Enums\OrganizationRole;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Verwaltung der API-Tokens in der Oberfläche: anlegen, einmalig anzeigen,
 * widerrufen — und die Grenzen, die die Rolle setzt.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_lists_the_tokens_of_the_active_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $other = Organization::factory()->create();

        ApiToken::issue($user, $organization, $user, 'Eigenes', [ApiScope::ProjectRead]);
        ApiToken::issue($other, $other, null, 'Fremdes', [ApiScope::ProjectRead]);

        $this->actingAs($user)->get('/einstellungen/konto/zugriffstoken')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('api-tokens/Index')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Eigenes')
                ->where('tokens.0.kind', 'personal')
            );
    }

    public function test_a_new_token_is_shown_in_plain_text_exactly_once(): void
    {
        $user = User::factory()->create();
        Organization::factory()->withMember($user)->create();

        $this->actingAs($user)->post('/einstellungen/konto/zugriffstoken', [
            'name' => 'Aus der CI',
            'kind' => 'personal',
            'scopes' => ['project:read'],
            'expires_in_days' => null,
        ])
            ->assertRedirect('/einstellungen/konto/zugriffstoken')
            ->assertSessionHas('createdToken.name', 'Aus der CI');

        $value = session('createdToken')['value'];
        $this->assertIsString($value);

        // Erster Aufruf nach dem Anlegen: der Wert ist da …
        $this->actingAs($user)->get('/einstellungen/konto/zugriffstoken')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('createdToken.value', $value));

        // … und beim nächsten Aufruf nicht mehr.
        $this->actingAs($user)->get('/einstellungen/konto/zugriffstoken')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('createdToken', null));

        // Gespeichert ist nur der Abdruck, nie der Wert selbst.
        $token = ApiToken::query()->firstOrFail();
        [, $plain] = explode('|', $value, 2);

        $this->assertSame(hash('sha256', $plain), $token->token);
        $this->assertStringStartsWith('errstack_', $plain);
    }

    public function test_a_token_may_not_grant_more_than_the_own_role(): void
    {
        $viewer = User::factory()->create();
        Organization::factory()->withMember($viewer, OrganizationRole::Viewer)->create();

        // Lesend darf lesende Bereiche vergeben …
        $this->actingAs($viewer)->post('/einstellungen/konto/zugriffstoken', [
            'name' => 'Nur lesen',
            'kind' => 'personal',
            'scopes' => ['project:read'],
        ])->assertSessionHasNoErrors();

        // … aber keine schreibenden.
        $this->actingAs($viewer)->post('/einstellungen/konto/zugriffstoken', [
            'name' => 'Doch schreiben',
            'kind' => 'personal',
            'scopes' => ['project:write'],
        ])->assertSessionHasErrors('scopes');

        $this->assertSame(['Nur lesen'], ApiToken::query()->pluck('name')->all());
    }

    public function test_only_the_administration_creates_organization_wide_tokens(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();

        $this->actingAs($member)->post('/einstellungen/konto/zugriffstoken', [
            'name' => 'Server',
            'kind' => 'organization',
            'scopes' => ['project:read'],
        ])->assertSessionHasErrors('kind');

        $admin = User::factory()->create();
        $organization->setRole($admin, OrganizationRole::Admin);

        $this->actingAs($admin)->post('/einstellungen/konto/zugriffstoken', [
            'name' => 'Server',
            'kind' => 'organization',
            'scopes' => ['project:read'],
        ])->assertSessionHasNoErrors();

        $token = ApiToken::query()->firstOrFail();

        $this->assertSame($organization->getMorphClass(), $token->tokenable_type);
        $this->assertSame($admin->id, $token->created_by_id);
    }

    public function test_the_name_stays_unique_within_the_organization(): void
    {
        $user = User::factory()->create();
        Organization::factory()->withMember($user)->create();

        $payload = ['name' => 'Doppelt', 'kind' => 'personal', 'scopes' => ['project:read']];

        $this->actingAs($user)->post('/einstellungen/konto/zugriffstoken', $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/einstellungen/konto/zugriffstoken', $payload)->assertSessionHasErrors('name');
    }

    public function test_the_own_token_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Member)->create();
        $token = ApiToken::issue($user, $organization, $user, 'Weg damit', [ApiScope::ProjectRead]);

        $this->actingAs($user)
            ->delete('/einstellungen/konto/zugriffstoken/'.$token->accessToken->getKey())
            ->assertRedirect('/einstellungen/konto/zugriffstoken');

        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_a_member_does_not_revoke_the_token_of_someone_else(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withMember($owner)->create();

        $member = User::factory()->create();
        $organization->setRole($member, OrganizationRole::Member);

        $token = ApiToken::issue($owner, $organization, $owner, 'Fremd', [ApiScope::ProjectRead]);

        $this->actingAs($member)
            ->delete('/einstellungen/konto/zugriffstoken/'.$token->accessToken->getKey())
            ->assertForbidden();

        $this->assertSame(1, ApiToken::query()->count());
    }

    public function test_outsiders_do_not_touch_tokens_of_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $token = ApiToken::issue($organization, $organization, null, 'Server', [ApiScope::ProjectRead]);

        $outsider = User::factory()->create();
        Organization::factory()->withMember($outsider)->create();

        $this->actingAs($outsider)
            ->delete('/einstellungen/konto/zugriffstoken/'.$token->accessToken->getKey())
            ->assertForbidden();
    }

    public function test_without_an_organization_the_page_points_to_the_organizations(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/einstellungen/konto/zugriffstoken')
            ->assertRedirect('/einstellungen/organisationen');
    }
}
