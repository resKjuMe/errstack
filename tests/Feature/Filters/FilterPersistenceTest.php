<?php

namespace Tests\Feature\Filters;

use App\Models\Environment;
use App\Models\FilterPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Der gewählte Filter bleibt über Seitenwechsel und Anmeldungen hinweg stehen —
 * und ein Link mit ausdrücklichen Parametern gewinnt trotzdem gegen ihn.
 */
class FilterPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Organization, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);
        Environment::factory()->for($project)->create(['name' => 'production']);

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    /**
     * Die Übersicht einer Organisation — die erste Auswertungsseite. Seit U5
     * trägt jede Fachseite die Organisation in der Adresse.
     */
    private function dashboard(Organization $organization): string
    {
        return "/organisationen/{$organization->slug}/uebersicht";
    }

    /**
     * Der Kern des Tasks: einmal eingestellt, gilt der Filter auf der nächsten
     * Auswertungsseite weiter — auch wenn deren Adresse keine Parameter trägt.
     */
    public function test_the_selection_survives_a_page_change_without_parameters(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->get($this->dashboard($organization).'?projects[]=webshop&environment=production&period=7d')
            ->assertOk();

        $this->actingAs($user)
            ->get("/organisationen/{$organization->slug}/versionen")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', ['webshop'])
                ->where('filter.value.environment', 'production')
                ->where('filter.value.period', '7d')
            );
    }

    /**
     * Die Links der Seitenleiste tragen den Filter mit — sonst zeigte die
     * Adresse am Ziel den Ausschnitt nicht mehr an, den man dort sieht.
     */
    public function test_the_sidebar_links_carry_the_current_filter(): void
    {
        [$user, $organization] = $this->context();

        $response = $this->actingAs($user)
            ->get($this->dashboard($organization).'?projects[]=webshop&environment=production&period=7d');

        $response->assertOk();
        $links = $this->navLinks($response);

        $issues = $links[__('nav.links.issues')];

        $this->assertStringContainsString('projects%5B%5D=webshop', $issues);
        $this->assertStringContainsString('environment=production', $issues);
        $this->assertStringContainsString('period=7d', $issues);

        // Die Verwaltung ist keine Auswertung: dort gibt es nichts zu filtern.
        // Seit U6 steht sie nicht mehr in der Hauptnavigation, sondern hinter
        // dem Anker im Fuß — auch der bleibt nackt.
        foreach ($this->navLinks($response) as $href) {
            $this->assertStringContainsString('?', $href, "Ohne Filter: {$href}");
        }

        $footer = $response->viewData('page')['props']['shell']['footer'];

        $this->assertStringNotContainsString('?', $footer[0]['href']);
    }

    /**
     * Ohne Auswertung keine Parameter: von den Einstellungen aus führen die
     * Links auf die nackte Adresse — dort greift am Ziel der gemerkte Stand.
     */
    public function test_a_page_without_an_analysis_leaves_the_links_bare(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&period=7d')->assertOk();

        $response = $this->actingAs($user)->get(route('profile.edit'));
        $response->assertOk();

        $this->assertStringNotContainsString('?', $this->navLinks($response)[__('nav.links.issues')]);
    }

    /**
     * Die Adressen der Seitenleiste, nach ihrer Beschriftung — die Navigation
     * kommt seit U1 gruppiert (App\Support\ShellData::nav).
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, string>
     */
    private function navLinks(TestResponse $response): array
    {
        $page = $response->viewData('page');
        $shell = is_array($page) ? ($page['props']['shell'] ?? []) : [];
        $hrefs = [];

        foreach (is_array($shell) ? ($shell['nav'] ?? []) : [] as $group) {
            foreach (is_array($group) ? ($group['links'] ?? []) : [] as $link) {
                if (is_array($link)) {
                    $hrefs[(string) $link['label']] = (string) $link['href'];
                }
            }
        }

        return $hrefs;
    }

    /**
     * Gemerkt wird je Konto und Organisation — und zwar dauerhaft, nicht in der
     * Sitzung: nach dem Abmelden steht die Auswahl wieder da.
     */
    public function test_the_selection_is_remembered_across_sessions(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&period=7d')->assertOk();
        $this->post('/logout');

        $this->assertDatabaseHas('filter_preferences', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'period' => '7d',
        ]);

        $this->actingAs($user)
            ->get($this->dashboard($organization))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', ['webshop'])
                ->where('filter.value.period', '7d')
            );
    }

    /**
     * Ein geteilter Link behält seine Bedeutung: der Empfänger sieht den
     * Ausschnitt des Links, nicht seinen eigenen gemerkten Stand — und zwar
     * ganz, nicht Feld für Feld ergänzt.
     */
    public function test_an_explicit_address_beats_the_remembered_selection(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&environment=production&period=7d')->assertOk();

        $this->actingAs($user)
            ->get($this->dashboard($organization).'?period=1h')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.period', '1h')
                ->where('filter.value.projects', [])
                ->where('filter.value.environment', '')
            );
    }

    /**
     * Die Zeitzone zählt nicht als Auswahl: die Oberfläche trägt sie von sich
     * aus nach, und täte sie es mit dieser Wirkung, käme der gemerkte Stand nie
     * wieder zum Zug.
     */
    public function test_a_pinned_timezone_alone_does_not_count_as_a_selection(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&period=7d')->assertOk();

        $this->actingAs($user)
            ->get($this->dashboard($organization).'?tz=Europe%2FBerlin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', ['webshop'])
                ->where('filter.value.period', '7d')
                ->where('filter.timezone', 'Europe/Berlin')
            );
    }

    /**
     * Ein gemerktes Projekt, das es nicht mehr gibt, fällt still heraus — die
     * Seite zeigt wieder alle statt einer Fehlermeldung. Dasselbe gilt für eine
     * gelöschte Umgebung.
     */
    public function test_a_deleted_project_falls_back_to_the_default_without_an_error(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&environment=production&period=7d')->assertOk();

        $project->delete();

        $this->actingAs($user)
            ->get($this->dashboard($organization))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', [])
                ->where('filter.value.environment', '')
                // Der Zeitraum hängt nicht am Projekt und bleibt deshalb stehen.
                ->where('filter.value.period', '7d')
            );
    }

    /**
     * Der Wechsel der Organisation setzt die Projektauswahl zurück — Projekte
     * gehören zu einer Organisation. Der Zeitraum bleibt: man wechselt, um
     * denselben Ausschnitt woanders zu sehen.
     */
    public function test_switching_the_organization_resets_the_project_selection(): void
    {
        [$user, $organization] = $this->context();
        $other = Organization::factory()->withMember($user)->create();
        Project::factory()->for($other)->create(['slug' => 'blog']);

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&period=7d')->assertOk();

        // Der Wechsel führt auf dieselbe Seite in der neuen Organisation (U5) —
        // ohne die Projektauswahl der alten.
        $this->actingAs($user)
            ->from($this->dashboard($organization).'?projects[]=webshop&period=7d')
            ->post("/einstellungen/organisationen/{$other->slug}/wechseln")
            ->assertRedirect($this->dashboard($other).'?period=7d');

        $this->actingAs($user)
            ->get($this->dashboard($other).'?period=7d')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', [])
                ->where('filter.value.period', '7d')
            );
    }

    /**
     * Und zurück: jede Organisation behält ihren eigenen Stand, statt dass der
     * eine den anderen überschreibt.
     */
    public function test_every_organization_keeps_its_own_selection(): void
    {
        [$user, $organization] = $this->context();
        $other = Organization::factory()->withMember($user)->create();
        Project::factory()->for($other)->create(['slug' => 'blog']);

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&period=7d')->assertOk();
        $this->actingAs($user)->get($this->dashboard($other).'?projects[]=blog&period=30d')->assertOk();

        $this->actingAs($user)
            ->get($this->dashboard($organization))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', ['webshop'])
                ->where('filter.value.period', '7d')
            );

        $this->assertSame(2, FilterPreference::query()->where('user_id', $user->id)->count());
    }

    /**
     * Der eigene Zeitraum kommt mit seinen Grenzen zurück — bei den relativen
     * bleiben sie leer, sonst sähe „letzte 7 Tage" beim nächsten Aufruf wie ein
     * fester Ausschnitt aus.
     */
    public function test_only_an_own_period_remembers_its_bounds(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)->get($this->dashboard($organization).'?period=custom&from=2026-08-01&to=2026-08-05')->assertOk();

        $preference = FilterPreference::query()->firstOrFail();
        $this->assertSame('custom', $preference->period);
        $this->assertSame('2026-08-01', $preference->custom_from?->format('Y-m-d'));
        $this->assertSame('2026-08-05', $preference->custom_to?->format('Y-m-d'));

        $this->actingAs($user)->get($this->dashboard($organization).'?period=7d')->assertOk();

        $preference = FilterPreference::query()->firstOrFail();
        $this->assertNull($preference->custom_from);
        $this->assertNull($preference->custom_to);
    }

    /**
     * Seiten ohne Auswertungsbezug merken nichts und zeigen keine Leiste — der
     * gemerkte Stand bleibt unangetastet.
     */
    public function test_a_page_without_an_analysis_leaves_the_remembered_selection_alone(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)->get($this->dashboard($organization).'?projects[]=webshop&period=7d')->assertOk();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->actingAs($user)
            ->get($this->dashboard($organization))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter.value.projects', ['webshop'])
                ->where('filter.value.period', '7d')
            );
    }
}
