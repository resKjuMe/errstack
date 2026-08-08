<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Enums\OrganizationRole;
use App\Models\ApiToken;
use App\Models\Commit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commits einer Auslieferung übergeben — der Weg für eine Bauumgebung **ohne**
 * Anbindung.
 *
 * Der Aufruf steht in einer Auslieferungs-Pipeline, und das prägt jede Prüfung
 * hier: er muss wiederholbar sein, und „wiederholbar" heißt bei einer Liste
 * nicht „tut beim zweiten Mal nichts", sondern „führt beim zweiten Mal zum
 * selben Ergebnis".
 */
class ReleaseCommitApiTest extends TestCase
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
     * @return array{Organization, Project, Release}
     */
    private function context(): array
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $project = Project::factory()->for($organization)->create(['slug' => 'webshop']);
        $release = Release::factory()->for($project)->version('1.2.0')->create();

        return [$organization, $project, $release];
    }

    private function url(Organization $organization, Project $project, string $version = '1.2.0'): string
    {
        return "/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases/{$version}/commits";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'repository' => 'acme/webshop',
            'commits' => [
                [
                    'id' => 'aaaa111',
                    'message' => "Warenkorb rechnet richtig\n\nDie Steuer wurde zweimal addiert.",
                    'author_name' => 'Alex Autor',
                    'author_email' => 'alex@acme.test',
                    'timestamp' => '2026-03-09T10:00:00+00:00',
                    'patch_set' => [
                        ['path' => 'app/Cart.php', 'type' => 'M'],
                        ['path' => 'tests/CartTest.php', 'type' => 'A'],
                    ],
                ],
                [
                    'id' => 'bbbb222',
                    'message' => 'Altes Preisschema entfernt',
                    'author_email' => 'bea@acme.test',
                    'patch_set' => [
                        ['path' => 'app/OldPricing.php', 'type' => 'D'],
                    ],
                ],
                [
                    'id' => 'cccc333',
                    'message' => 'Abhängigkeiten aktualisiert',
                ],
            ],
        ];
    }

    public function test_commits_can_be_handed_over_for_a_release(): void
    {
        [$organization, $project, $release] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), $this->payload())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', 'aaaa111')
            ->assertJsonPath('data.0.repository', 'acme/webshop')
            ->assertJsonPath('data.0.patch_set.0.path', 'app/Cart.php');

        // Das Repository entsteht von selbst: die Übergabe kommt aus einer
        // Bauumgebung ohne Anbindung, und das ist der Fall, für den es sie gibt.
        $repository = Repository::query()->sole();

        $this->assertSame('acme/webshop', $repository->name);
        $this->assertSame($organization->id, $repository->organization_id);
        $this->assertSame(Repository::PROVIDER_MANUAL, $repository->provider);

        $this->assertSame(3, $release->commits()->count());

        $first = Commit::query()->where('sha', 'aaaa111')->sole();

        $this->assertSame('Alex Autor', $first->author_name);
        $this->assertSame('2026-03-09 10:00:00', $first->committed_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $first->files()->count());
    }

    /**
     * Die Reihenfolge der Übergabe wird gemerkt und nicht aus `committed_at`
     * neu erfunden: nach einem Rebase gibt die Zeit eines Commits seine
     * Stellung verkehrt herum wieder.
     */
    public function test_commits_keep_the_order_they_were_handed_over_in(): void
    {
        [$organization, $project, $release] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'repository' => 'acme/webshop',
                'commits' => [
                    ['id' => 'aaaa111', 'timestamp' => '2026-03-09T12:00:00+00:00'],
                    ['id' => 'bbbb222', 'timestamp' => '2026-03-09T08:00:00+00:00'],
                ],
            ])
            ->assertOk();

        $this->assertSame(
            ['aaaa111', 'bbbb222'],
            $release->commits()->pluck('sha')->all(),
        );
    }

    /**
     * Der zweite Lauf derselben Pipeline führt zum selben Ergebnis — nicht zur
     * doppelten Liste.
     */
    public function test_handing_over_the_same_commits_twice_does_not_duplicate_them(): void
    {
        [$organization, $project, $release] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)->postJson($this->url($organization, $project), $this->payload())->assertOk();
        $this->withToken($bearer)->postJson($this->url($organization, $project), $this->payload())->assertOk();

        $this->assertSame(3, $release->commits()->count());
        $this->assertSame(3, Commit::query()->count());
        $this->assertSame(1, Repository::query()->count());

        // Und auch die Dateien bleiben einfach vorhanden, statt sich zu
        // verdoppeln: sie werden ersetzt und nicht ergänzt.
        $this->assertSame(2, Commit::query()->where('sha', 'aaaa111')->sole()->files()->count());
    }

    /**
     * Eine zweite Übergabe **setzt** die Liste: was sie nicht mehr nennt, ist
     * nicht mehr Teil der Auslieferung. Der Commit selbst bleibt — er gehört
     * seinem Repository und steckt womöglich in einer anderen Version.
     */
    public function test_a_second_hand_over_replaces_the_list_without_deleting_commits(): void
    {
        [$organization, $project, $release] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)->postJson($this->url($organization, $project), $this->payload())->assertOk();

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'repository' => 'acme/webshop',
                'commits' => [['id' => 'cccc333']],
            ])
            ->assertOk();

        $this->assertSame(['cccc333'], $release->commits()->pluck('sha')->all());
        $this->assertSame(3, Commit::query()->count());
    }

    /**
     * Eine leere Liste ist eine Angabe und kein Versehen: der Weg, eine falsche
     * Übergabe zurückzunehmen, ohne die Version zu löschen.
     */
    public function test_an_empty_list_clears_the_commits_of_a_release(): void
    {
        [$organization, $project, $release] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)->postJson($this->url($organization, $project), $this->payload())->assertOk();

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), ['commits' => []])
            ->assertOk();

        $this->assertSame(0, $release->commits()->count());
    }

    /**
     * Derselbe Commit steckt in `1.2.0` und in dem Nachzügler `1.2.1` — das ist
     * der Grund für die Zwischentabelle.
     */
    public function test_a_commit_can_belong_to_several_releases(): void
    {
        [$organization, $project, $release] = $this->context();
        $later = Release::factory()->for($project)->version('1.2.1')->create();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $body = ['repository' => 'acme/webshop', 'commits' => [['id' => 'aaaa111']]];

        $this->withToken($bearer)->postJson($this->url($organization, $project), $body)->assertOk();
        $this->withToken($bearer)->postJson($this->url($organization, $project, '1.2.1'), $body)->assertOk();

        $this->assertSame(1, Commit::query()->count());
        $this->assertSame(1, $release->commits()->count());
        $this->assertSame(1, $later->commits()->count());
    }

    /**
     * Die Zuordnung zum Konto ist der Grund, die Adresse überhaupt zu lesen:
     * erst mit ihr lässt sich ein Commit jemandem zeigen, statt nur eine
     * Adresse zu nennen.
     */
    public function test_authors_are_matched_to_accounts_by_email(): void
    {
        [$organization, $project] = $this->context();

        $member = User::factory()->create(['email' => 'Alex@Acme.test']);
        $organization->setRole($member, OrganizationRole::Member);

        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), $this->payload())
            ->assertOk();

        // Groß- und Kleinschreibung spielt keine Rolle: „Alex@Acme.test" im
        // Konto und „alex@acme.test" im Commit sind dieselbe Person.
        $this->assertSame($member->id, Commit::query()->where('sha', 'aaaa111')->sole()->author_id);

        // Und wer kein Konto hat, behält Name und Adresse — nur ohne Verweis.
        $second = Commit::query()->where('sha', 'bbbb222')->sole();

        $this->assertNull($second->author_id);
        $this->assertSame('bea@acme.test', $second->author_email);
    }

    /**
     * Die Adresse in einem Commit ist eine Angabe aus dem Repository und kein
     * Nachweis. Sie auf jedes Konto zu beziehen hieße, dass ein beliebiger
     * Baulauf einen fremden Namen anheften kann.
     */
    public function test_authors_outside_the_organization_are_not_matched(): void
    {
        [$organization, $project] = $this->context();

        User::factory()->create(['email' => 'alex@acme.test']);

        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), $this->payload())
            ->assertOk();

        $this->assertNull(Commit::query()->where('sha', 'aaaa111')->sole()->author_id);
    }

    /**
     * Ohne Repository-Angabe landete die Übergabe wortlos im Nichts, und in der
     * Pipeline sähe das aus wie ein Erfolg.
     */
    public function test_a_commit_without_any_repository_is_rejected(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'commits' => [['id' => 'aaaa111']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commits.0.repository');
    }

    public function test_the_commits_of_a_release_can_be_read_back(): void
    {
        [$organization, $project] = $this->context();

        $this->withToken($this->bearer($organization, [ApiScope::ProjectWrite]))
            ->postJson($this->url($organization, $project), $this->payload())
            ->assertOk();

        $this->withToken($this->bearer($organization, [ApiScope::ProjectRead]))
            ->getJson($this->url($organization, $project))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.id', 'bbbb222')
            ->assertJsonPath('data.1.patch_set.0.type', 'D');
    }

    public function test_writing_commits_needs_the_write_scope(): void
    {
        [$organization, $project] = $this->context();

        $this->withToken($this->bearer($organization, [ApiScope::ProjectRead]))
            ->postJson($this->url($organization, $project), $this->payload())
            ->assertStatus(403);
    }

    /**
     * Der Aufruf, den eine Pipeline eigentlich machen will: Version melden und
     * sagen, was drinsteckt — in einem Schritt.
     */
    public function test_commits_can_be_handed_over_while_announcing_the_release(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $project = Project::factory()->for($organization)->create(['slug' => 'webshop']);
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson("/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases", [
                'version' => '1.3.0',
                ...$this->payload(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.version', '1.3.0');

        $release = Release::query()->where('version', '1.3.0')->sole();

        $this->assertSame(3, $release->commits()->count());
    }

    /**
     * Wer beim zweiten Aufruf nur die Auslieferungszeit nachträgt, soll damit
     * nicht die Commit-Liste verlieren.
     */
    public function test_announcing_a_release_again_without_commits_keeps_them(): void
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $project = Project::factory()->for($organization)->create(['slug' => 'webshop']);
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);
        $url = "/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases";

        $this->withToken($bearer)
            ->postJson($url, ['version' => '1.3.0', ...$this->payload()])
            ->assertStatus(201);

        $this->withToken($bearer)
            ->postJson($url, ['version' => '1.3.0', 'released_at' => '2026-03-10T09:00:00+00:00'])
            ->assertOk();

        $this->assertSame(3, Release::query()->where('version', '1.3.0')->sole()->commits()->count());
    }
}
