<?php

namespace Tests\Feature\Integrations;

use App\Enums\ExternalIssueState;
use App\Enums\IssueActivityType;
use App\Enums\OrganizationRole;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aus einem Fehler ein Ticket machen — oder ihn an ein vorhandenes hängen.
 */
class GitHubIssueLinkTest extends TestCase
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
     * @return array{User, Organization, Issue, Integration}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Owner)->create();
        $user->switchOrganization($organization);

        $project = Project::factory()->for($organization)->create();
        $issue = Issue::factory()->for($project)->create();

        $integration = Integration::factory()->for($organization)->create();

        Repository::factory()->for($organization)->create([
            'name' => 'acme/webshop',
            'integration_id' => $integration->id,
        ]);

        return [$user, $organization, $issue, $integration];
    }

    public function test_a_ticket_is_created_and_linked(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake([
            'api.github.com/repos/acme/webshop/issues' => Http::response([
                'number' => 42,
                'html_url' => 'https://github.com/acme/webshop/issues/42',
                'title' => 'TypeError in Kasse',
                'state' => 'open',
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'repository' => 'acme/webshop',
            ])
            ->assertRedirect();

        $link = IssueLink::query()->sole();

        $this->assertSame('acme/webshop#42', $link->reference());
        $this->assertTrue($link->created_remotely);
        $this->assertSame(ExternalIssueState::Open, $link->state);

        // Der Rumpf trägt den Link zurück — das ist der Teil, der nicht altert.
        Http::assertSent(fn (Request $request): bool => str_contains(
            (string) ($request->data()['body'] ?? ''),
            route('issues.show', [$organization, $issue]),
        ));

        $this->assertSame(
            IssueActivityType::ExternalLinked,
            IssueActivity::query()->where('issue_id', $issue->id)->sole()->type,
        );
    }

    public function test_an_existing_ticket_is_looked_up_before_it_is_linked(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake([
            'api.github.com/repos/acme/webshop/issues/7' => Http::response([
                'number' => 7,
                'html_url' => 'https://github.com/acme/webshop/issues/7',
                'title' => 'Kasse hängt',
                'state' => 'open',
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'repository' => 'acme/webshop',
                'number' => 7,
            ])
            ->assertRedirect();

        $link = IssueLink::query()->sole();

        $this->assertFalse($link->created_remotely);
        $this->assertSame('Kasse hängt', $link->title);
    }

    public function test_a_ticket_that_does_not_exist_becomes_a_field_error(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake([
            'api.github.com/repos/acme/webshop/issues/9999' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->actingAs($user)
            ->from(route('issues.show', [$organization, $issue]))
            ->post(route('issues.links.store', [$organization, $issue]), [
                'repository' => 'acme/webshop',
                'number' => 9999,
            ])
            ->assertSessionHasErrors('number');

        $this->assertSame(0, IssueLink::query()->count());
    }

    public function test_only_a_connected_repository_can_be_used(): void
    {
        [$user, $organization, $issue] = $this->context();

        Http::fake();

        // Ein freies Textfeld hieße, dass jedes Mitglied mit dem Token der
        // Organisation in jedem erreichbaren Repository Tickets anlegen kann.
        $this->actingAs($user)
            ->post(route('issues.links.store', [$organization, $issue]), [
                'repository' => 'fremd/geheim',
            ])
            ->assertSessionHasErrors('repository');

        Http::assertNothingSent();
    }

    public function test_unlinking_leaves_the_ticket_alone(): void
    {
        [$user, $organization, $issue] = $this->context();

        $link = IssueLink::factory()->for($issue)->create();

        Http::fake();

        $this->actingAs($user)
            ->delete(route('issues.links.destroy', [$organization, $issue, $link]))
            ->assertRedirect();

        $this->assertSame(0, IssueLink::query()->count());

        // Gelöst wird die Aussage über die beiden, nicht eines von beiden.
        Http::assertNothingSent();

        $this->assertSame(
            IssueActivityType::ExternalUnlinked,
            IssueActivity::query()->where('issue_id', $issue->id)->sole()->type,
        );
    }

    public function test_a_link_of_another_issue_cannot_be_removed(): void
    {
        [$user, $organization, $issue] = $this->context();

        $other = Issue::factory()->for($issue->project)->create();
        $link = IssueLink::factory()->for($other)->create();

        $this->actingAs($user)
            ->delete(route('issues.links.destroy', [$organization, $issue, $link]))
            ->assertNotFound();

        $this->assertSame(1, IssueLink::query()->count());
    }
}
