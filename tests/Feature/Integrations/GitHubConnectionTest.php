<?php

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationStatus;
use App\Enums\OrganizationRole;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Repository;
use App\Models\User;
use App\Support\Integrations\GitHub\GitHubClient;
use App\Support\Integrations\GitHub\GitHubException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Verbinden, auswählen, lösen — und der Fall, für den es die Anbindung
 * eigentlich braucht: der Zugang wird abgelehnt, und das steht danach in der
 * Oberfläche statt still zu bleiben.
 */
class GitHubConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.client_id' => 'client-id',
            'services.github.client_secret' => 'client-secret',
            'services.github.webhook_secret' => 'hook-secret',
        ]);

        // Ein Aufruf, den kein `Http::fake()` abdeckt, geht sonst **wirklich**
        // hinaus — in der CI gegen api.github.com, und das Ergebnis ist ein
        // `401` mitten in einem Test, der von GitHub nichts wissen will.
        Http::preventStrayRequests();
    }

    /**
     * @return array{User, Organization}
     */
    private function context(OrganizationRole $role = OrganizationRole::Owner): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();

        $user->switchOrganization($organization);

        return [$user, $organization];
    }

    public function test_connecting_stores_an_encrypted_token(): void
    {
        [$user, $organization] = $this->context();

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_secret']),
            'api.github.com/user' => Http::response(['login' => 'acme', 'id' => 42]),
        ]);

        $redirect = $this->actingAs($user)
            ->get(route('organizations.integrations.github.redirect', $organization));

        $redirect->assertRedirectContains('login/oauth/authorize');

        $state = session('github_oauth_state');

        $this->actingAs($user)
            ->get(route('integrations.github.callback', ['code' => 'abc', 'state' => $state]))
            ->assertRedirect(route('organizations.integrations.index', $organization));

        $integration = Integration::query()->sole();

        $this->assertSame('acme', $integration->account);
        $this->assertSame(IntegrationStatus::Connected, $integration->status);
        $this->assertSame('gho_secret', $integration->token());
        $this->assertSame($user->id, $integration->connected_by_id);

        // Verschlüsselt in der Spalte: wer die Datenbank liest, soll damit nicht
        // bei GitHub schreiben können.
        $stored = (string) $integration->getRawOriginal('credentials');
        $this->assertStringNotContainsString('gho_secret', $stored);
    }

    public function test_a_mismatched_state_does_not_connect(): void
    {
        [$user] = $this->context();

        // Kein `state` in der Sitzung: entweder abgelaufen oder untergeschoben.
        // Beide Male gibt es nichts zu verbinden.
        $this->actingAs($user)
            ->get(route('integrations.github.callback', ['code' => 'abc', 'state' => 'fremd:acme']))
            ->assertRedirect(route('organizations.index'));

        $this->assertSame(0, Integration::query()->count());
    }

    public function test_github_rejecting_the_exchange_does_not_connect(): void
    {
        [$user, $organization] = $this->context();

        // GitHub antwortet auf einen abgelehnten Tausch mit `200` und einem
        // `error`-Feld. Wer nur auf den Status sieht, legt eine Anbindung mit
        // leerem Token an.
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code']),
        ]);

        $this->actingAs($user)->get(route('organizations.integrations.github.redirect', $organization));

        $this->actingAs($user)
            ->get(route('integrations.github.callback', ['code' => 'abc', 'state' => session('github_oauth_state')]))
            ->assertRedirect(route('organizations.integrations.index', $organization));

        $this->assertSame(0, Integration::query()->count());
    }

    public function test_a_member_cannot_connect(): void
    {
        [$user, $organization] = $this->context(OrganizationRole::Member);

        $this->actingAs($user)
            ->get(route('organizations.integrations.github.redirect', $organization))
            ->assertForbidden();
    }

    public function test_selecting_a_repository_marks_it_as_coming_from_the_integration(): void
    {
        [$user, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->create();

        Http::fake([
            'api.github.com/repos/acme/webshop/hooks' => Http::response([]),
            'api.github.com/*' => Http::response([]),
        ]);

        $this->actingAs($user)
            ->post(route('organizations.integrations.repositories.store', $organization), [
                'name' => 'acme/webshop',
                'external_id' => '4711',
                'url' => 'https://github.com/acme/webshop',
            ])
            ->assertRedirect();

        $repository = Repository::query()->sole();

        $this->assertSame($integration->id, $repository->integration_id);
        $this->assertSame('github', $repository->provider);
        $this->assertSame('4711', $repository->external_id);
    }

    public function test_a_repository_that_already_exists_keeps_its_commits(): void
    {
        [$user, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->create();

        // Von Hand eingetragen, bevor es die Anbindung gab — der Regelfall bei
        // einer Organisation, die vorher ohne gearbeitet hat.
        $existing = Repository::factory()->for($organization)->create(['name' => 'acme/webshop']);

        Http::fake(['api.github.com/*' => Http::response([])]);

        $this->actingAs($user)
            ->post(route('organizations.integrations.repositories.store', $organization), [
                'name' => 'acme/webshop',
            ])
            ->assertRedirect();

        $this->assertSame(1, Repository::query()->count());
        $this->assertSame($integration->id, $existing->refresh()->integration_id);
    }

    public function test_losing_the_access_is_recorded_on_the_integration(): void
    {
        [, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->create();

        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        try {
            (new GitHubClient($integration))->viewer();
            $this->fail('Ein abgelehnter Zugang muss als Ausnahme herauskommen.');
        } catch (GitHubException $e) {
            $this->assertTrue($e->accessRejected);
        }

        $integration->refresh();

        $this->assertSame(IntegrationStatus::Disconnected, $integration->status);
        $this->assertSame('Bad credentials', $integration->last_error);
        $this->assertFalse($integration->isUsable());
    }

    public function test_a_short_outage_does_not_disconnect(): void
    {
        [, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->create();

        Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], 500)]);

        try {
            (new GitHubClient($integration))->viewer();
            $this->fail('Ein Fehlschlag muss als Ausnahme herauskommen.');
        } catch (GitHubException $e) {
            // Eine Störung geht von selbst vorbei — sie darf niemanden zwingen,
            // die Anbindung von Hand zu erneuern.
            $this->assertFalse($e->accessRejected);
        }

        $this->assertSame(IntegrationStatus::Connected, $integration->refresh()->status);
    }

    public function test_a_rate_limit_does_not_count_as_a_lost_connection(): void
    {
        [, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->create();

        // GitHub antwortet mit `403` sowohl auf „du darfst hier nicht
        // schreiben" als auch auf „du hast zu viele Aufrufe gemacht". Das
        // zweite geht in einer Stunde von selbst vorbei — es als verlorenen
        // Zugang zu führen hieße, jemanden zum Neu-Verbinden zu schicken, weil
        // eine Auslieferung zu viele Commits hatte.
        Http::fake([
            'api.github.com/*' => Http::response(
                ['message' => 'API rate limit exceeded for user ID 1.'],
                403,
                ['X-RateLimit-Remaining' => '0'],
            ),
        ]);

        try {
            (new GitHubClient($integration))->viewer();
            $this->fail('Eine Begrenzung muss als Ausnahme herauskommen.');
        } catch (GitHubException $e) {
            $this->assertFalse($e->accessRejected);
        }

        $this->assertSame(IntegrationStatus::Connected, $integration->refresh()->status);
    }

    public function test_the_page_shows_the_lost_connection(): void
    {
        [$user, $organization] = $this->context();

        Integration::factory()->for($organization)->disconnected()->create();

        $this->actingAs($user)
            ->get(route('organizations.integrations.index', $organization))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('integrations/Index')
                // Seit X4 stehen mehrere Anbieter auf der Seite, jeder in
                // seinem Abschnitt — GitHub ist einer davon.
                ->where('github.integration.status', 'disconnected')
                ->where('github.integration.lastError', 'Bad credentials'));
    }

    public function test_disconnecting_keeps_the_repositories(): void
    {
        [$user, $organization] = $this->context();

        $integration = Integration::factory()->for($organization)->create();
        $repository = Repository::factory()->for($organization)->create([
            'integration_id' => $integration->id,
        ]);

        $this->actingAs($user)
            ->delete(route('organizations.integrations.destroy', [$organization, $integration]))
            ->assertRedirect();

        $this->assertSame(0, Integration::query()->count());

        // Das Repository fällt auf den Stand zurück, den es ohne Anbindung
        // hätte — es verschwindet nicht samt seinen Commits.
        $this->assertNotNull($repository->refresh());
        $this->assertNull($repository->integration_id);
    }
}
