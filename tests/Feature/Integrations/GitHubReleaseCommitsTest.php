<?php

namespace Tests\Feature\Integrations;

use App\Enums\ApiScope;
use App\Enums\IntegrationStatus;
use App\Enums\OrganizationRole;
use App\Jobs\FetchReleaseCommits;
use App\Models\ApiToken;
use App\Models\Commit;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
use App\Support\Integrations\GitHub\GitHubReleaseCommits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * „Was steckt in dieser Auslieferung?" — beantwortet, ohne dass eine Pipeline
 * die Commits übergeben muss.
 */
class GitHubReleaseCommitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein Aufruf, den kein `Http::fake()` abdeckt, geht sonst **wirklich**
        // hinaus — in der CI gegen api.github.com, und das Ergebnis ist ein
        // `401` mitten in einem Test, der von GitHub nichts wissen will.
        Http::preventStrayRequests();
    }

    /**
     * @return array{Organization, Project, Integration}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $project = Project::factory()->for($organization)->create();
        $integration = Integration::factory()->for($organization)->create();

        Repository::factory()->for($organization)->create([
            'name' => 'acme/webshop',
            'integration_id' => $integration->id,
        ]);

        return [$organization, $project, $integration];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commit(string $sha, string $message = 'Kasse repariert'): array
    {
        return [
            'sha' => $sha,
            'commit' => [
                'message' => $message,
                'author' => [
                    'name' => 'Anna Beck',
                    'email' => 'anna@example.com',
                    'date' => '2026-08-01T10:00:00Z',
                ],
            ],
        ];
    }

    public function test_the_commits_between_two_releases_are_fetched(): void
    {
        [, $project] = $this->context();

        Release::factory()->for($project)->version('1.0.0')->create(['ref' => 'aaa']);
        $release = Release::factory()->for($project)->version('1.1.0')->create(['ref' => 'bbb']);

        Http::fake([
            'api.github.com/repos/acme/webshop/compare/*' => Http::response([
                'commits' => [self::commit('c1'), self::commit('c2', 'Preis gerundet')],
            ]),
        ]);

        $this->assertSame(2, GitHubReleaseCommits::fetch($release));

        $this->assertSame(2, $release->commits()->count());
        $this->assertSame('Kasse repariert', Commit::query()->where('sha', 'c1')->sole()->message);

        // Der Vorgänger ist der Bezugspunkt — sonst käme „alles seit Beginn der
        // Zeit" heraus, und das ist keine Antwort auf „was ist neu".
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'compare/aaa...bbb'));
    }

    public function test_without_a_previous_release_the_latest_commits_are_taken(): void
    {
        [, $project] = $this->context();

        $release = Release::factory()->for($project)->version('1.0.0')->create(['ref' => 'bbb']);

        Http::fake([
            'api.github.com/repos/acme/webshop/commits*' => Http::response([self::commit('c1')]),
        ]);

        $this->assertSame(1, GitHubReleaseCommits::fetch($release));
    }

    public function test_commits_that_were_handed_over_are_not_overwritten(): void
    {
        [, $project] = $this->context();

        $release = Release::factory()->for($project)->version('1.0.0')->create(['ref' => 'bbb']);

        $repository = Repository::query()->sole();
        $commit = Commit::factory()->for($repository)->create();
        $release->commits()->attach($commit->id, ['position' => 0]);

        Http::fake();

        // Die Liste des Absenders ist die genauere Angabe: sie kennt den
        // tatsächlich gebauten Stand, während dieser Weg ihn erschließt.
        $this->assertSame(0, GitHubReleaseCommits::fetch($release));

        Http::assertNothingSent();
    }

    public function test_a_release_without_a_ref_fetches_nothing(): void
    {
        [, $project] = $this->context();

        // Der Regelfall bei einer Version, die aus Meldungen entstanden ist
        // (R1): sie kennt ihre Nummer, nicht den Commit dahinter.
        $release = Release::factory()->for($project)->version('1.0.0')->create(['ref' => null]);

        Http::fake();

        $this->assertSame(0, GitHubReleaseCommits::fetch($release));

        Http::assertNothingSent();
    }

    public function test_a_lost_connection_ends_the_job_instead_of_retrying(): void
    {
        [, $project, $integration] = $this->context();

        $release = Release::factory()->for($project)->version('1.0.0')->create(['ref' => 'bbb']);

        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        // Der Auftrag darf nicht scheitern und wiederholt werden: der nächste
        // Versuch käme genauso weit. Festgehalten ist es an der Anbindung.
        (new FetchReleaseCommits($release->id))->handle();

        $this->assertSame(IntegrationStatus::Disconnected, $integration->refresh()->status);
        $this->assertSame(0, $release->commits()->count());
    }

    public function test_announcing_a_release_with_a_ref_queues_the_fetch(): void
    {
        [$organization, $project] = $this->context();

        Queue::fake();

        $bearer = ApiToken::issue(
            tokenable: $organization,
            organization: $organization,
            createdBy: null,
            name: 'Test',
            scopes: [ApiScope::ProjectWrite],
        )->plainTextToken;

        // Der Aufruf steht am Ende einer Auslieferung und wartet auf die
        // Antwort — das Holen gehört deshalb in die Warteschlange und nicht in
        // diese Anfrage.
        $this->withToken($bearer)
            ->postJson("/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases", [
                'version' => '2.0.0',
                'ref' => 'ccc',
            ])
            ->assertSuccessful();

        Queue::assertPushed(
            FetchReleaseCommits::class,
            fn (FetchReleaseCommits $job): bool => $job->releaseId === Release::query()
                ->where('version', '2.0.0')->sole()->id,
        );
    }

    public function test_a_release_that_brings_its_own_commits_does_not_queue_a_fetch(): void
    {
        [$organization, $project] = $this->context();

        Queue::fake();

        $bearer = ApiToken::issue(
            tokenable: $organization,
            organization: $organization,
            createdBy: null,
            name: 'Test',
            scopes: [ApiScope::ProjectWrite],
        )->plainTextToken;

        $this->withToken($bearer)
            ->postJson("/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases", [
                'version' => '2.0.0',
                'ref' => 'ccc',
                'repository' => 'acme/webshop',
                'commits' => [['id' => 'c1', 'message' => 'Von der Pipeline']],
            ])
            ->assertSuccessful();

        Queue::assertNotPushed(FetchReleaseCommits::class);
    }
}
