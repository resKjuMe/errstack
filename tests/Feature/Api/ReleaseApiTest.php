<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Versionen über die öffentliche Schnittstelle: ankündigen, auflisten,
 * nachschlagen.
 *
 * Der Endpunkt ist für den Aufruf aus einer Auslieferungs-Pipeline gedacht, und
 * das prägt jede Prüfung hier: er muss **wiederholbar** sein, denn eine
 * Pipeline läuft bei einem Fehlschlag noch einmal.
 */
class ReleaseApiTest extends TestCase
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

    /**
     * @return array{Organization, Project}
     */
    private function context(): array
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $project = Project::factory()->for($organization)->create(['slug' => 'webshop']);

        return [$organization, $project];
    }

    private function url(Organization $organization, Project $project, string $suffix = ''): string
    {
        return "/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases{$suffix}";
    }

    public function test_a_release_can_be_announced_before_the_first_event(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'version' => '1.4.0',
                'ref' => 'a1b2c3d',
                'url' => 'https://github.com/acme/webshop/releases/tag/1.4.0',
                'released_at' => '2026-03-10T09:00:00+00:00',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.version', '1.4.0')
            ->assertJsonPath('data.ref', 'a1b2c3d')
            ->assertJsonPath('data.is_semver', true)
            // Angekündigt heißt: noch kein einziges Ereignis. Ein Nullwert und
            // nicht der Zeitpunkt der Ankündigung — die beiden sagen
            // Verschiedenes.
            ->assertJsonPath('data.first_event', null);

        $release = Release::query()->sole();

        $this->assertSame($project->id, $release->project_id);
        $this->assertSame(1, $release->sort_major);
        $this->assertSame(4, $release->sort_minor);
    }

    /**
     * Derselbe Aufruf ein zweites Mal ist kein Fehler, sondern eine Ergänzung.
     */
    public function test_announcing_the_same_version_twice_updates_instead_of_failing(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), ['version' => '1.4.0'])
            ->assertStatus(201);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'version' => '1.4.0',
                'ref' => 'nachgereicht',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.ref', 'nachgereicht');

        $this->assertSame(1, Release::query()->count());
    }

    /**
     * Ein weggelassenes Feld leert nichts: sonst würde der zweite Aufruf
     * derselben Pipeline die Auslieferungszeit des ersten wegwerfen.
     */
    public function test_a_second_call_without_a_field_keeps_the_earlier_value(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'version' => '1.4.0',
                'released_at' => '2026-03-10T09:00:00+00:00',
            ])
            ->assertStatus(201);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), ['version' => '1.4.0'])
            ->assertStatus(200);

        $this->assertNotNull(Release::query()->sole()->released_at);
    }

    public function test_a_version_without_a_number_is_accepted_but_has_no_order(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), ['version' => 'a1b2c3d4'])
            ->assertStatus(201)
            ->assertJsonPath('data.is_semver', false);

        $this->assertNull(Release::query()->sole()->sort_major);
    }

    public function test_the_version_is_required(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('version');
    }

    public function test_writing_needs_the_write_scope(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectRead]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), ['version' => '1.0.0'])
            ->assertStatus(403);
    }

    public function test_the_list_only_shows_the_versions_of_this_project(): void
    {
        [$organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create(['slug' => 'intranet']);

        Release::factory()->for($project)->version('1.0.0')->create();
        Release::factory()->for($other)->version('9.9.9')->create();

        $bearer = $this->bearer($organization, [ApiScope::ProjectRead]);

        $this->withToken($bearer)
            ->getJson($this->url($organization, $project))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', '1.0.0');
    }

    public function test_a_single_version_is_addressable_by_its_string(): void
    {
        [$organization, $project] = $this->context();

        Release::factory()->for($project)->version('mein-dienst@2.1.0')->create([
            'released_at' => Carbon::parse('2026-03-01 08:00:00'),
        ]);

        $bearer = $this->bearer($organization, [ApiScope::ProjectRead]);

        $this->withToken($bearer)
            ->getJson($this->url($organization, $project, '/'.rawurlencode('mein-dienst@2.1.0')))
            ->assertStatus(200)
            ->assertJsonPath('data.version', 'mein-dienst@2.1.0')
            ->assertJsonPath('data.is_semver', true);
    }

    public function test_an_unknown_version_is_a_404(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectRead]);

        $this->withToken($bearer)
            ->getJson($this->url($organization, $project, '/gibt-es-nicht'))
            ->assertStatus(404);
    }

    /**
     * Ein Token gilt für seine Organisation. Ein Projekt einer fremden ist über
     * diese Adresse nicht erreichbar — und die Antwort ist 404 und nicht 403:
     * ob es die Organisation überhaupt gibt, geht ein fremdes Token nichts an.
     */
    public function test_a_project_of_another_organization_is_out_of_reach(): void
    {
        [$organization, $project] = $this->context();

        $foreign = Organization::factory()->create(['slug' => 'fremd']);
        $bearer = $this->bearer($foreign, [ApiScope::ProjectRead]);

        $this->withToken($bearer)
            ->getJson($this->url($organization, $project))
            ->assertStatus(404);
    }
}
