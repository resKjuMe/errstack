<?php

namespace Tests\Feature\Organizations;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Adressen der Fachseiten tragen die Organisation (U5).
 *
 * Der Kern ist nicht die Schreibweise des Pfades, sondern was sie zusagt: ein
 * Link steht für sich. Wer ihn verschickt, verschickt die Organisation mit — und
 * beim Empfänger öffnet sich dasselbe, unabhängig davon, was der zuletzt
 * angesehen hat. Die Prüfungen hier sind genau diese Zusage, Satz für Satz.
 */
class OrganizationScopedUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project}
     */
    private function context(string $slug = 'acme'): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create(['slug' => $slug]);
        $project = Project::factory()->for($organization)->create();

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    public function test_the_business_pages_live_under_their_organization(): void
    {
        [$user, $organization] = $this->context();

        $expected = [
            'dashboard' => "/organisationen/{$organization->slug}/uebersicht",
            'issues.index' => "/organisationen/{$organization->slug}/fehler",
            'tags.index' => "/organisationen/{$organization->slug}/merkmale",
            'feedback.index' => "/organisationen/{$organization->slug}/rueckmeldungen",
            'releases.index' => "/organisationen/{$organization->slug}/versionen",
            'performance.index' => "/organisationen/{$organization->slug}/leistung",
            'performance.issues.index' => "/organisationen/{$organization->slug}/leistungsprobleme",
            'web-vitals.index' => "/organisationen/{$organization->slug}/ladeerlebnis",
            'profiling.index' => "/organisationen/{$organization->slug}/leistung/profile",
        ];

        $this->actingAs($user);

        foreach ($expected as $name => $path) {
            $this->assertSame($path, route($name, absolute: false), "Adresse von {$name}");
            $this->get(route($name))->assertOk();
        }
    }

    /**
     * Die Adresse entscheidet, nicht die zuletzt gewählte Organisation. Wer den
     * Link eines Kollegen öffnet, landet in dessen Organisation — und bleibt
     * danach dort, damit die Navigation dasselbe zeigt wie die Seite.
     */
    public function test_the_address_decides_which_organization_is_shown(): void
    {
        $user = User::factory()->create();
        $first = Organization::factory()->withMember($user)->create(['slug' => 'erste']);
        $second = Organization::factory()->withMember($user)->create(['slug' => 'zweite']);
        $user->switchOrganization($first);

        $this->actingAs($user)
            ->get(route('issues.index', $second))
            ->assertOk();

        $this->assertSame($second->id, $user->fresh()->current_organization_id);
    }

    /**
     * Eine Organisation, der man nicht angehört, ist keine Auskunft wert — auch
     * dann nicht, wenn ihr Slug bekannt ist.
     */
    public function test_a_foreign_organization_is_forbidden(): void
    {
        [$user] = $this->context();
        $stranger = Organization::factory()->create(['slug' => 'fremd']);
        $strangerProject = Project::factory()->for($stranger)->create();
        Issue::factory()->for($strangerProject)->create(['title' => 'Geheim']);

        $this->actingAs($user)
            ->get(route('issues.index', $stranger))
            ->assertForbidden()
            ->assertDontSee('Geheim');
    }

    /**
     * Die alte Adresse führt weiterhin ans Ziel — dauerhaft und mit den Feldern
     * der Filterleiste, denn in ihnen steckt, was der Absender gemeint hat.
     */
    public function test_an_old_address_leads_to_the_new_one(): void
    {
        [$user, $organization, $project] = $this->context();

        $location = $this->actingAs($user)
            ->get('/fehler?period=7d&projects[]='.$project->slug)
            ->assertStatus(301)
            ->headers->get('Location');

        $this->assertSame(
            "/organisationen/{$organization->slug}/fehler",
            parse_url((string) $location, PHP_URL_PATH),
        );

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        $this->assertSame('7d', $query['period'] ?? null);
        $this->assertSame([$project->slug], array_values((array) ($query['projects'] ?? [])));
    }

    /**
     * Auch für die Unterpfade — sonst wäre die Weiterleitung eine Liste, die
     * beim nächsten neuen Pfad nicht mitwächst.
     */
    public function test_an_old_address_below_the_root_leads_along(): void
    {
        [$user, $organization, $project] = $this->context();
        $issue = Issue::factory()->for($project)->create();

        $this->actingAs($user)
            ->get("/fehler/{$issue->id}")
            ->assertStatus(301)
            ->assertRedirect("/organisationen/{$organization->slug}/fehler/{$issue->id}");
    }

    /**
     * Der Umschalter führt auf dieselbe Seite in der neuen Organisation — und
     * nicht auf die Adresse der alten, die prompt wieder zurückschaltete.
     */
    public function test_switching_stays_on_the_same_page(): void
    {
        $user = User::factory()->create();
        $first = Organization::factory()->withMember($user)->create(['slug' => 'erste']);
        $second = Organization::factory()->withMember($user)->create(['slug' => 'zweite']);
        $user->switchOrganization($first);

        $this->actingAs($user)
            ->from(route('releases.index', $first).'?period=7d')
            ->post(route('organizations.switch', $second))
            ->assertRedirect(route('releases.index', $second).'?period=7d');
    }

    /**
     * Eine Detailseite hat in der neuen Organisation kein Gegenstück: dort führt
     * der Wechsel in die Liste desselben Bereichs statt in eine
     * Zugriffs-Fehlermeldung.
     */
    public function test_switching_from_a_detail_page_lands_in_the_list(): void
    {
        $user = User::factory()->create();
        $first = Organization::factory()->withMember($user)->create(['slug' => 'erste']);
        $second = Organization::factory()->withMember($user)->create(['slug' => 'zweite']);
        $user->switchOrganization($first);

        $issue = Issue::factory()->for(Project::factory()->for($first))->create();

        $this->actingAs($user)
            ->from(route('issues.show', ['organization' => $first, 'issue' => $issue]))
            ->post(route('organizations.switch', $second))
            ->assertRedirect(route('issues.index', $second));
    }

    /**
     * Vom Wechsel auf einer Seite ohne Organisation bleibt es bei ihr: die
     * Organisationsliste zeigt danach ohnehin die neue Wahl.
     */
    public function test_switching_outside_an_organization_stays_put(): void
    {
        $user = User::factory()->create();
        Organization::factory()->withMember($user)->create(['slug' => 'erste']);
        $second = Organization::factory()->withMember($user)->create(['slug' => 'zweite']);

        $this->actingAs($user)
            ->from(route('organizations.index'))
            ->post(route('organizations.switch', $second))
            ->assertRedirect(route('organizations.index'));
    }

    /**
     * Die Navigation zeigt auf die neuen Adressen — und auf keine alte.
     */
    public function test_the_navigation_points_at_the_new_addresses(): void
    {
        [$user, $organization] = $this->context();

        $shell = $this->actingAs($user)
            ->get(route('dashboard'))
            ->viewData('page')['props']['shell'];

        $hrefs = [];

        foreach ($shell['nav'] as $group) {
            foreach ($group['links'] as $link) {
                $hrefs[] = $link['href'];
            }
        }

        $this->assertNotSame([], $hrefs);

        foreach ($hrefs as $href) {
            $path = (string) parse_url($href, PHP_URL_PATH);

            $this->assertDoesNotMatchRegularExpression(
                '#^/(fehler|merkmale|rueckmeldungen|versionen|leistung|leistungsprobleme|ladeerlebnis|spur)(/|$)#',
                $path,
                "Alte Adresse in der Navigation: {$href}",
            );
        }

        $this->assertContains(route('issues.index', $organization), $hrefs);
    }

    /**
     * Ohne Mitgliedschaft gibt es diese Adressen nicht — dann steht in der
     * Leiste kein Link darauf, statt einer, der in eine Fehlermeldung führt.
     */
    public function test_without_an_organization_the_business_pages_are_absent(): void
    {
        $shell = $this->actingAs(User::factory()->create())
            ->get(route('organizations.index'))
            ->viewData('page')['props']['shell'];

        $labels = [];

        foreach ($shell['nav'] as $group) {
            $labels = [...$labels, ...array_column($group['links'], 'label')];
        }

        $this->assertNotContains('Fehler', $labels);
        $this->assertNull($shell['undoHref']);

        // Seit U6 führt die Hauptnavigation die Verwaltung nicht mehr; der Weg
        // zur ersten Organisation steht als Anker im Fuß der Leiste.
        $this->assertContains(
            route('organizations.index'),
            array_column($shell['footer'], 'href'),
        );
    }
}
