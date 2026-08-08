<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Repositories über die öffentliche Schnittstelle — für den Fall, dass eine
 * Bauumgebung sie selbst anmeldet, statt dass jemand sie einträgt.
 *
 * Wie das Ankündigen einer Version ist der Aufruf **wiederholbar**: er steht in
 * einer Pipeline, und die läuft bei einem Fehlschlag noch einmal.
 */
class RepositoryApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<ApiScope>  $scopes
     */
    private function bearer(Organization $organization, array $scopes): string
    {
        return ApiToken::issue(
            tokenable: $organization,
            organization: $organization,
            createdBy: null,
            name: 'Test '.uniqid(),
            scopes: $scopes,
        )->plainTextToken;
    }

    private function url(Organization $organization): string
    {
        return "/api/0/organizations/{$organization->slug}/repos";
    }

    public function test_a_repository_can_be_connected_and_read_back(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);

        $this->withToken($this->bearer($organization, [ApiScope::OrgWrite]))
            ->postJson($this->url($organization), [
                'name' => 'acme/webshop',
                'url' => 'https://github.com/acme/webshop',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'acme/webshop')
            ->assertJsonPath('data.provider', Repository::PROVIDER_MANUAL);

        $this->withToken($this->bearer($organization, [ApiScope::OrgRead]))
            ->getJson($this->url($organization))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', 'https://github.com/acme/webshop');
    }

    /**
     * Der zweite Lauf derselben Pipeline ist kein Fehler — und er leert kein
     * Feld, das er nicht mitschickt.
     */
    public function test_connecting_the_same_repository_again_is_not_an_error(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $bearer = $this->bearer($organization, [ApiScope::OrgWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization), [
                'name' => 'acme/webshop',
                'url' => 'https://github.com/acme/webshop',
            ])
            ->assertStatus(201);

        $this->withToken($bearer)
            ->postJson($this->url($organization), ['name' => 'acme/webshop'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://github.com/acme/webshop');

        $this->assertSame(1, Repository::query()->count());
    }

    /**
     * Dasselbe Repository in zwei Organisationen sind zwei Zeilen — der
     * eindeutige Index gilt je Organisation.
     */
    public function test_the_same_name_may_exist_in_another_organization(): void
    {
        $one = Organization::factory()->create(['slug' => 'acme']);
        $two = Organization::factory()->create(['slug' => 'globex']);

        foreach ([$one, $two] as $organization) {
            // Zwei Anfragen mit **verschiedenen** Token in einem Test: der
            // Wächter merkt sich das Konto der ersten Anfrage, und die zweite
            // liefe sonst unter dem Token der ersten — die Prüfung wäre dann
            // nicht bloß nutzlos, sie schlüge fehl.
            $this->app['auth']->forgetGuards();
            $this->withToken($this->bearer($organization, [ApiScope::OrgWrite]))
                ->postJson($this->url($organization), ['name' => 'acme/webshop'])
                ->assertStatus(201);
        }

        $this->assertSame(2, Repository::query()->count());
    }

    public function test_connecting_needs_the_write_scope(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);

        $this->withToken($this->bearer($organization, [ApiScope::OrgRead]))
            ->postJson($this->url($organization), ['name' => 'acme/webshop'])
            ->assertStatus(403);
    }
}
