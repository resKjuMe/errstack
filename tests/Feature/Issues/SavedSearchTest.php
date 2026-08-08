<?php

namespace Tests\Feature\Issues;

use App\Enums\IssueSort;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SavedSearch;
use App\Models\SavedSearchDefault;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Gespeicherte Suchen und Standard-Ansichten (S5).
 *
 * Zwei Dinge werden hier immer wieder geprüft, weil sie das Eigentliche sind:
 * dass eine gespeicherte Suche **nur** Suchtext und Sortierung enthält — der
 * Zeitraum bleibt draußen —, und dass eine Freigabe Sichtbarkeit bedeutet und
 * nicht Schreibrecht.
 */
class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    public function test_eine_suche_wird_gespeichert(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post(route('issues.searches.store'), [
                'name' => '  Kritische offene Fehler  ',
                'q' => 'is:unresolved level:error',
                'sort' => IssueSort::TimesSeen->value,
                'shared' => true,
            ])
            ->assertRedirect();

        $search = SavedSearch::query()->firstOrFail();

        $this->assertSame('Kritische offene Fehler', $search->name);
        $this->assertSame('is:unresolved level:error', $search->query);
        $this->assertSame(IssueSort::TimesSeen, $search->sort);
        $this->assertTrue($search->shared);
        $this->assertSame($user->id, $search->user_id);
        $this->assertSame($organization->id, $search->organization_id);
    }

    /**
     * Der Vertrag dieser Aufgabe: Suchtext und Sortierung — sonst nichts.
     *
     * Der Zeitraum, die Projektauswahl und die Umgebung gehören der globalen
     * Filterleiste. Werden sie mitgeschickt, dürfen sie nirgends landen: eine
     * Ansicht, die den Zeitraum zurückstellt, reißt den Betrachter aus der
     * Untersuchung heraus, in der er gerade steckt.
     */
    public function test_zeitraum_und_projektauswahl_werden_nicht_mitgespeichert(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->post(route('issues.searches.store'), [
                'name' => 'Offen',
                'q' => 'is:unresolved',
                'sort' => IssueSort::LastSeen->value,
                'period' => '30d',
                'from' => '2026-01-01',
                'to' => '2026-02-01',
                'projects' => ['webshop'],
                'environment' => 'production',
            ])
            ->assertRedirect();

        $search = SavedSearch::query()->firstOrFail();
        $stored = array_keys($search->getAttributes());

        $this->assertSame('is:unresolved', $search->query);
        $this->assertSame(IssueSort::LastSeen, $search->sort);

        foreach (['period', 'from', 'to', 'projects', 'environment'] as $field) {
            $this->assertNotContains($field, $stored);
        }
    }

    public function test_eine_suche_wird_umbenannt_und_geloescht(): void
    {
        [$user, $organization] = $this->context();

        $search = SavedSearch::factory()->for($organization)->for($user)->create(['name' => 'Alt']);

        $this->actingAs($user)
            ->patch(route('issues.searches.update', $search), [
                'name' => 'Neu',
                'q' => 'is:ignored',
                'sort' => IssueSort::FirstSeen->value,
            ])
            ->assertRedirect();

        $search->refresh();

        $this->assertSame('Neu', $search->name);
        $this->assertSame('is:ignored', $search->query);
        $this->assertSame(IssueSort::FirstSeen, $search->sort);
        // Kein Häkchen im Rumpf heißt „nicht freigegeben" und nicht „unverändert":
        // das Formular schickt alle vier Felder, und ein fehlendes Häkchen ist die
        // Aussage, die es ist.
        $this->assertFalse($search->shared);

        $this->actingAs($user)
            ->delete(route('issues.searches.destroy', $search))
            ->assertRedirect();

        $this->assertSame(0, SavedSearch::query()->count());
    }

    public function test_zwei_gleichnamige_suchen_desselben_kontos_gehen_nicht(): void
    {
        [$user, $organization] = $this->context();

        SavedSearch::factory()->for($organization)->for($user)->create(['name' => 'Offen']);

        $this->actingAs($user)
            ->post(route('issues.searches.store'), ['name' => 'Offen', 'q' => 'is:unresolved'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, SavedSearch::query()->count());
    }

    /**
     * Derselbe Name in zwei Konten ist der Normalfall und kein Konflikt.
     */
    public function test_zwei_konten_duerfen_denselben_namen_verwenden(): void
    {
        [$user, $organization] = $this->context();

        $colleague = User::factory()->create();
        $organization->setRole($colleague, OrganizationRole::Member);
        $colleague->switchOrganization($organization);

        SavedSearch::factory()->for($organization)->for($user)->create(['name' => 'Meine Fehler']);

        $this->actingAs($colleague)
            ->post(route('issues.searches.store'), ['name' => 'Meine Fehler', 'q' => 'is:unresolved'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SavedSearch::query()->count());
    }

    public function test_freigegebene_suchen_sehen_alle_persoenliche_nicht(): void
    {
        [$user, $organization, $project] = $this->context();

        $colleague = User::factory()->create(['name' => 'Anna Berg']);
        $organization->setRole($colleague, OrganizationRole::Member);
        $colleague->switchOrganization($organization);

        SavedSearch::factory()->for($organization)->for($user)->shared()->create(['name' => 'Freigegeben']);
        SavedSearch::factory()->for($organization)->for($user)->create(['name' => 'Persönlich']);

        $this->actingAs($colleague)
            ->get(route('issues.index', ['projects' => [$project->slug]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('savedSearches.items.0.name', 'Freigegeben')
                ->where('savedSearches.items.0.own', false)
                ->count('savedSearches.items', 1));
    }

    public function test_eine_freigegebene_suche_aendert_nur_der_ersteller(): void
    {
        [$user, $organization] = $this->context();

        $colleague = User::factory()->create();
        $organization->setRole($colleague, OrganizationRole::Member);
        $colleague->switchOrganization($organization);

        $search = SavedSearch::factory()->for($organization)->for($user)->shared()->create();

        $this->actingAs($colleague)
            ->patch(route('issues.searches.update', $search), ['name' => 'Übernommen'])
            ->assertForbidden();

        $this->actingAs($colleague)
            ->delete(route('issues.searches.destroy', $search))
            ->assertForbidden();

        $this->assertSame(1, SavedSearch::query()->count());
    }

    public function test_eine_persoenliche_suche_ist_fuer_andere_nicht_da(): void
    {
        [$user, $organization] = $this->context();

        $colleague = User::factory()->create();
        $organization->setRole($colleague, OrganizationRole::Member);
        $colleague->switchOrganization($organization);

        $search = SavedSearch::factory()->for($organization)->for($user)->create();

        $this->actingAs($colleague)
            ->put(route('issues.searches.default.store', $search), ['project' => 'webshop'])
            ->assertForbidden();
    }

    /**
     * Der Einstieg: mit gesetztem Standard geht die Liste mit der Suche auf.
     *
     * Geprüft wird die **Weiterleitung** und nicht nur das Ergebnis: die Ansicht
     * soll in der Adresszeile stehen, damit sie weitergebbar bleibt und der
     * Suchtext im Feld steht.
     */
    public function test_der_standard_eines_projekts_leitet_die_liste_um(): void
    {
        [$user, $organization, $project] = $this->context();

        $search = SavedSearch::factory()->for($organization)->for($user)->create([
            'query' => 'is:unresolved level:error',
            'sort' => IssueSort::TimesSeen,
        ]);

        $this->actingAs($user)
            ->put(route('issues.searches.default.store', $search), ['project' => $project->slug])
            ->assertRedirect();

        $response = $this->actingAs($user)->get(route('issues.index'));

        $target = (string) $response->headers->get('Location');

        $response->assertRedirect();
        $this->assertStringContainsString('q='.rawurlencode('is:unresolved level:error'), $target);
        $this->assertStringContainsString('sort='.IssueSort::TimesSeen->value, $target);
        // Der Zustandsfilter tritt beiseite: was der Ausdruck über den Zustand
        // sagt, ist maßgeblich. Ohne das bliebe „Stummgeschaltet" zuverlässig
        // leer, weil die Vorgabe „offen" davorstünde.
        $this->assertStringContainsString('status=alle', $target);

        // Und die Weiterleitung wiederholt sich nicht: die Zieladresse trägt
        // `q` und geht damit als gewöhnliche Liste auf.
        $this->actingAs($user)->get($target)->assertOk();
    }

    public function test_eine_eigene_eingabe_schlaegt_den_standard(): void
    {
        [$user, $organization, $project] = $this->context();

        $search = SavedSearch::factory()->for($organization)->for($user)->create();

        SavedSearchDefault::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'saved_search_id' => $search->id,
        ]);

        // Ein ausdrücklich geleertes Suchfeld ist eine Aussage — sonst käme man
        // aus dem eigenen Einstieg nicht mehr heraus.
        $this->actingAs($user)->get(route('issues.index', ['q' => '']))->assertOk();

        $this->actingAs($user)->get(route('issues.index', ['q' => 'is:resolved']))->assertOk();
    }

    public function test_der_standard_laesst_sich_wieder_aufheben(): void
    {
        [$user, $organization, $project] = $this->context();

        $search = SavedSearch::factory()->for($organization)->for($user)->create();

        $this->actingAs($user)
            ->put(route('issues.searches.default.store', $search), ['project' => $project->slug])
            ->assertRedirect();

        $this->assertSame(1, SavedSearchDefault::query()->count());

        $this->actingAs($user)
            ->delete(route('issues.searches.default.destroy', $search), ['project' => $project->slug])
            ->assertRedirect();

        $this->assertSame(0, SavedSearchDefault::query()->count());
        $this->actingAs($user)->get(route('issues.index'))->assertOk();
    }

    /**
     * Der Standard gehört dem Betrachter, nicht dem Projekt: die Kollegin sieht
     * dieselbe freigegebene Suche in der Liste, ihre Fehlerliste geht aber
     * gewöhnlich auf.
     */
    public function test_der_standard_gilt_nur_fuer_das_eigene_konto(): void
    {
        [$user, $organization, $project] = $this->context();

        $colleague = User::factory()->create();
        $organization->setRole($colleague, OrganizationRole::Member);
        $colleague->switchOrganization($organization);

        $search = SavedSearch::factory()->for($organization)->for($user)->shared()->create();

        $this->actingAs($user)
            ->put(route('issues.searches.default.store', $search), ['project' => $project->slug])
            ->assertRedirect();

        $this->actingAs($colleague)->get(route('issues.index'))->assertOk();
        $this->actingAs($user)->get(route('issues.index'))->assertRedirect();
    }

    /**
     * Ein fremdes Projekt ist kein Projekt: derselbe Slug in einer anderen
     * Organisation darf hier nichts festlegen.
     */
    public function test_ein_fremdes_projekt_wird_nicht_gefunden(): void
    {
        [$user, $organization] = $this->context();

        $stranger = Organization::factory()->create();
        Project::factory()->for($stranger)->create(['slug' => 'fremd']);

        $search = SavedSearch::factory()->for($organization)->for($user)->create();

        $this->actingAs($user)
            ->put(route('issues.searches.default.store', $search), ['project' => 'fremd'])
            ->assertNotFound();
    }

    /**
     * Die Standard-Ansichten gibt es ohne Datenbankzeile — und sie sagen, ob sie
     * heute schon vollständig antworten können.
     */
    public function test_die_standard_ansichten_haengen_an_jeder_liste(): void
    {
        [$user, , $project] = $this->context();

        $this->actingAs($user)
            ->get(route('issues.index', ['projects' => [$project->slug]]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->count('savedSearches.views', 6)
                ->where('savedSearches.views.0.key', 'unresolved')
                ->where('savedSearches.views.0.query', 'is:unresolved')
                ->where('savedSearches.views.0.available', true)
                // „Zur Prüfung" ist seit S7 vollständig beantwortbar …
                ->where('savedSearches.views.1.key', 'for_review')
                ->where('savedSearches.views.1.available', true)
                // … „Wieder aufgetreten" noch nicht: die Rückfallerkennung ist
                // S8. Die Ansicht steht trotzdem da und sagt es.
                ->where('savedSearches.views.2.key', 'regressed')
                ->where('savedSearches.views.2.available', false)
                ->where('savedSearches.project.slug', 'webshop'));
    }

    /**
     * Mehr als ein Projekt in der Auswahl: „Standard für dieses Projekt" hat
     * dann kein Ziel und fehlt.
     */
    public function test_ohne_eindeutiges_projekt_gibt_es_keinen_standard(): void
    {
        [$user, $organization] = $this->context();

        Project::factory()->for($organization)->create(['name' => 'App', 'slug' => 'app']);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('savedSearches.project', null)
                ->where('savedSearches.defaultId', null));
    }
}
