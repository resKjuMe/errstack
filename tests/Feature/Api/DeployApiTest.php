<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Enums\IssueActivityType;
use App\Enums\IssueStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Jobs\DeliverPersonalNotification;
use App\Models\ApiToken;
use App\Models\Commit;
use App\Models\Deploy;
use App\Models\Environment;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
use App\Notifications\PreferenceScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Auslieferungen melden — der Aufruf am Ende einer Auslieferungs-Pipeline.
 *
 * Geprüft wird nicht nur, dass die Zeile entsteht, sondern was aus ihr folgt:
 * die wartenden Fehler-Einträge werden aufgelöst, die Beteiligten
 * benachrichtigt — und beides **nur** für die Umgebung, in der eine
 * Auslieferung „draußen" bedeutet. Ein Staging-Deploy, der Einträge erledigt,
 * wäre der teuerste Fehler dieser Aufgabe: er nähme Fehler aus der Liste, die
 * bei den Nutzern weiterlaufen.
 */
class DeployApiTest extends TestCase
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
        $project = Project::factory()->for($organization)->create([
            'slug' => 'webshop',
            'default_environment' => 'production',
        ]);
        $release = Release::factory()->for($project)->version('1.2.0')->create();

        return [$organization, $project, $release];
    }

    private function url(Organization $organization, Project $project, string $version = '1.2.0'): string
    {
        return "/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases/{$version}/deploys";
    }

    public function test_a_deploy_can_be_reported_for_a_release(): void
    {
        [$organization, $project, $release] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'environment' => 'production',
                'name' => 'Build 4711',
                'url' => 'https://ci.acme.test/runs/4711',
                'started_at' => '2026-03-09T10:00:00+00:00',
                'finished_at' => '2026-03-09T10:04:00+00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.environment', 'production')
            ->assertJsonPath('data.name', 'Build 4711');

        $deploy = Deploy::query()->sole();

        $this->assertSame($release->id, $deploy->release_id);
        $this->assertSame($project->id, $deploy->project_id);
        $this->assertSame('2026-03-09 10:04:00', $deploy->finished_at->utc()->format('Y-m-d H:i:s'));

        $this->withToken($this->bearer($organization, [ApiScope::ProjectRead]))
            ->getJson($this->url($organization, $project))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', 'https://ci.acme.test/runs/4711');
    }

    /**
     * Ohne Angaben ist der Aufruf immer noch vollständig: die Umgebung ist die
     * des Projekts, der Zeitpunkt der des Aufrufs. Das ist der Aufruf, den eine
     * Pipeline tatsächlich absetzt.
     */
    public function test_a_deploy_without_details_uses_the_default_environment_and_now(): void
    {
        [$organization, $project] = $this->context();

        $this->withToken($this->bearer($organization, [ApiScope::ProjectWrite]))
            ->postJson($this->url($organization, $project), [])
            ->assertCreated()
            ->assertJsonPath('data.environment', 'production');

        $deploy = Deploy::query()->sole();

        // Der Zeitpunkt des Aufrufs — auf die Sekunde genau zu prüfen wäre ein
        // Test auf die Uhr; gemeint ist „eben jetzt und nicht irgendwann".
        $this->assertTrue($deploy->finished_at->diffInMinutes(CarbonImmutable::now()) < 1);
        $this->assertNull($deploy->started_at);
    }

    /**
     * Zweimal auszuliefern ist zweimal ausgeliefert — nach einem Rollback ist
     * genau das der Normalfall, und die zweite Zeile ist die Auskunft darüber.
     */
    public function test_reporting_the_same_deploy_twice_records_two_deploys(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)->postJson($this->url($organization, $project), [])->assertCreated();
        $this->withToken($bearer)->postJson($this->url($organization, $project), [])->assertCreated();

        $this->assertSame(2, Deploy::query()->count());
    }

    /**
     * Eine unbekannte Umgebung entsteht mit dem Deploy — wie beim Aufnehmen.
     * Ihre „gesehen"-Zeitpunkte bleiben dabei leer: von dort kam noch keine
     * Meldung, und ein Deploy ist keine.
     */
    public function test_an_unknown_environment_is_created_without_seen_timestamps(): void
    {
        [$organization, $project] = $this->context();

        $this->withToken($this->bearer($organization, [ApiScope::ProjectWrite]))
            ->postJson($this->url($organization, $project), ['environment' => 'kunde-a'])
            ->assertCreated();

        $environment = Environment::query()->where('name', 'kunde-a')->sole();

        $this->assertSame($project->id, $environment->project_id);
        $this->assertNull($environment->last_seen_at);
    }

    /**
     * „Erledigt im nächsten Release" wird mit der Auslieferung zu „erledigt in
     * dieser Version" — der Vermerk bekommt seinen Bezugspunkt.
     */
    public function test_a_production_deploy_resolves_issues_awaiting_the_next_release(): void
    {
        [$organization, $project, $release] = $this->context();

        $waiting = Issue::factory()->for($project)->create([
            'status' => IssueStatus::Resolved,
            'resolved_in_next_release' => true,
        ]);

        $open = Issue::factory()->for($project)->create([
            'status' => IssueStatus::Unresolved,
            'resolved_in_next_release' => false,
        ]);

        $this->withToken($this->bearer($organization, [ApiScope::ProjectWrite]))
            ->postJson($this->url($organization, $project), ['environment' => 'production'])
            ->assertCreated();

        $waiting->refresh();

        $this->assertFalse($waiting->resolved_in_next_release);
        $this->assertSame($release->id, $waiting->resolved_in_release_id);
        $this->assertSame(IssueStatus::Resolved, $waiting->status);

        $open->refresh();

        $this->assertNull($open->resolved_in_release_id);
        $this->assertSame(IssueStatus::Unresolved, $open->status);

        // Der Verlauf sagt, was passiert ist — mit Version und Umgebung als
        // Werten, damit er eine gelöschte Version überlebt.
        $activity = IssueActivity::query()
            ->where('issue_id', $waiting->id)
            ->where('type', IssueActivityType::Deployed)
            ->sole();

        $this->assertSame('1.2.0', $activity->data['release'] ?? null);
        $this->assertSame('production', $activity->data['environment'] ?? null);
        $this->assertNull($activity->user_id);
    }

    /**
     * Der Kern des Vertrags: eine Auslieferung nach `staging` löst **keine**
     * Produktions-Logik aus. Der Fix ist dort nicht bei den Nutzern angekommen,
     * und den Eintrag aufzulösen hieße, ihn aus der Liste zu nehmen, während
     * der Fehler draußen weiterläuft.
     */
    public function test_a_staging_deploy_leaves_issues_awaiting_the_next_release_alone(): void
    {
        [$organization, $project] = $this->context();

        $waiting = Issue::factory()->for($project)->create([
            'status' => IssueStatus::Resolved,
            'resolved_in_next_release' => true,
        ]);

        $this->withToken($this->bearer($organization, [ApiScope::ProjectWrite]))
            ->postJson($this->url($organization, $project), ['environment' => 'staging'])
            ->assertCreated();

        $waiting->refresh();

        $this->assertTrue($waiting->resolved_in_next_release);
        $this->assertNull($waiting->resolved_in_release_id);

        $this->assertSame(0, IssueActivity::query()->where('type', IssueActivityType::Deployed)->count());
    }

    /**
     * Benachrichtigt werden die Autoren der enthaltenen Commits — und nur die,
     * deren Adresse sich einem Konto zuordnen ließ.
     *
     * Der Anlass „Auslieferung" ist per Mail **standardmäßig aus** (A5): was
     * mehrmals täglich passiert, schaltet sonst den ersten überrannten
     * Posteingang ab. Der Test stellt ihn deshalb ausdrücklich an — geprüft
     * wird hier der Kreis der Empfänger, nicht die Vorgabe.
     */
    public function test_commit_authors_are_notified_about_a_deploy(): void
    {
        Queue::fake();

        [$organization, $project, $release] = $this->context();

        $author = User::factory()->create();

        NotificationPreference::put(
            $author,
            PreferenceScope::global(),
            NotificationEventType::Deploy,
            NotificationTransport::Mail,
            true,
        );

        $repository = Repository::factory()->for($organization)->create();

        $commit = Commit::factory()->for($repository)->create(['author_id' => $author->id]);
        $foreign = Commit::factory()->for($repository)->create(['author_id' => null]);

        $release->commits()->attach([$commit->id => ['position' => 0], $foreign->id => ['position' => 1]]);

        $this->withToken($this->bearer($organization, [ApiScope::ProjectWrite]))
            ->postJson($this->url($organization, $project), [])
            ->assertCreated();

        // Genau einer: der Commit ohne zugeordnetes Konto hat keinen Empfänger,
        // und eine Mail an eine fremde Adresse aus einem Repository heraus wäre
        // etwas anderes als eine Benachrichtigung.
        Queue::assertPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->user->is($author)
                && $job->event === NotificationEventType::Deploy,
        );

        Queue::assertPushed(DeliverPersonalNotification::class, 1);
    }

    public function test_reporting_a_deploy_requires_the_write_scope(): void
    {
        [$organization, $project] = $this->context();

        $this->withToken($this->bearer($organization, [ApiScope::ProjectRead]))
            ->postJson($this->url($organization, $project), [])
            ->assertForbidden();

        $this->assertSame(0, Deploy::query()->count());
    }

    public function test_reporting_a_deploy_for_an_unknown_release_is_not_found(): void
    {
        [$organization, $project] = $this->context();

        $this->withToken($this->bearer($organization, [ApiScope::ProjectWrite]))
            ->postJson($this->url($organization, $project, '9.9.9'), [])
            ->assertNotFound();
    }
}
