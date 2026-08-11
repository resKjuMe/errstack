<?php

namespace Tests\Feature\Discover;

use App\Enums\FilterPeriod;
use App\Enums\OrganizationRole;
use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SavedQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Gespeicherte Auswertungen (D3): benennen, beschreiben, freigeben,
 * duplizieren, löschen — und als Dashboard-Kachel übernehmen.
 *
 * Drei Dinge werden hier immer wieder geprüft, weil sie das Eigentliche sind:
 * dass der **Zeitraum mitgespeichert** wird (anders als bei der gespeicherten
 * Suche) und beim Öffnen wieder dasteht, dass eine **Freigabe Sichtbarkeit
 * bedeutet und nicht Schreibrecht**, und dass eine übernommene Kachel die Frage
 * als **Kopie** bekommt und den Zeitraum ausdrücklich nicht.
 */
class SavedQueryTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-07 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        CarbonImmutable::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
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

    /**
     * Die Eingabe, wie die Seite sie abschickt: die Frage in den Feldern der
     * Abfrage-Leiste, der Ausschnitt in denen der Filterleiste.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Fehler nach Browser',
            'description' => 'Welcher Browser fällt auf?',
            'shared' => false,
            'dataset' => 'errors',
            'fields' => ['browser.name'],
            'metrics' => ['count()'],
            'q' => 'level:error',
            'sort' => '-count',
            'limit' => 25,
            'interval' => '1h',
            'period' => FilterPeriod::Last7Days->value,
            'environment' => 'production',
            'projects' => ['webshop'],
        ], $overrides);
    }

    public function test_eine_auswertung_wird_gespeichert(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post(route('discover.saved.store', $organization), $this->payload([
                'name' => '  Fehler nach Browser  ',
                'shared' => true,
            ]))
            ->assertRedirect();

        $saved = SavedQuery::query()->firstOrFail();

        $this->assertSame('Fehler nach Browser', $saved->name);
        $this->assertSame('Welcher Browser fällt auf?', $saved->description);
        $this->assertTrue($saved->shared);
        $this->assertSame($user->id, $saved->user_id);
        $this->assertSame($organization->id, $saved->organization_id);

        $query = $saved->discoverQuery();

        $this->assertSame('errors', $query->dataset->value);
        $this->assertSame(['browser.name'], $query->fields);
        $this->assertSame(['count()'], $query->metrics);
        $this->assertSame('level:error', $query->search);
        $this->assertSame('-count', $query->sort);
        $this->assertSame(25, $query->limit);
        $this->assertSame('1h', $query->interval);
    }

    /**
     * Der Vertrag dieser Aufgabe: der Zeitraum wird mitgespeichert.
     *
     * Genau umgekehrt zur gespeicherten Suche — und aus dem Grund, der in der
     * Migration steht: eine Auswertung ist eine Frage samt ihrem Ausschnitt.
     */
    public function test_zeitraum_umgebung_und_projekt_werden_mitgespeichert(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post(route('discover.saved.store', $organization), $this->payload())
            ->assertRedirect();

        $filters = SavedQuery::query()->firstOrFail()->savedFilters();

        $this->assertSame(FilterPeriod::Last7Days, $filters->period);
        $this->assertSame('production', $filters->environment);
        $this->assertSame('webshop', $filters->projectSlug);
    }

    /**
     * Ohne Angabe zum Ausschnitt bleibt die Spalte leer — „sagt nichts" und
     * „sagt nichts, aber ausdrücklich" wären sonst zwei Zustände mit derselben
     * Wirkung.
     */
    public function test_ohne_ausschnitt_bleibt_die_spalte_leer(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post(route('discover.saved.store', $organization), $this->payload([
                'period' => null,
                'environment' => null,
                'projects' => [],
            ]))
            ->assertRedirect();

        $this->assertNull(SavedQuery::query()->firstOrFail()->filters);
    }

    /**
     * Beim Öffnen steht der vollständige Zustand in der Adresse: die Frage und
     * der gespeicherte Ausschnitt.
     */
    public function test_beim_oeffnen_steht_der_vollstaendige_zustand_in_der_adresse(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->post(route('discover.saved.store', $organization), $this->payload())
            ->assertRedirect();

        $response = $this->actingAs($user)->get(route('discover.index', [
            'organization' => $organization,
            'projects' => ['webshop'],
            'tz' => 'UTC',
        ]));

        $href = $response->viewData('page')['props']['saved']['items'][0]['href'];

        $parameters = [];
        parse_str((string) parse_url($href, PHP_URL_QUERY), $parameters);

        $this->assertSame('errors', $parameters['dataset']);
        $this->assertSame(['browser.name'], $parameters['fields']);
        $this->assertSame(['count()'], $parameters['metrics']);
        $this->assertSame('level:error', $parameters['q']);
        $this->assertSame('-count', $parameters['sort']);
        $this->assertSame('25', $parameters['limit']);
        $this->assertSame('1h', $parameters['interval']);

        // Und der Ausschnitt — das ist der Unterschied zur gespeicherten Suche.
        $this->assertSame(FilterPeriod::Last7Days->value, $parameters['period']);
        $this->assertSame('production', $parameters['environment']);
        $this->assertSame(['webshop'], $parameters['projects']);
    }

    /**
     * Was die Auswertung nicht gespeichert hat, bleibt stehen, wie es ist: eine
     * ohne Umgebung reißt niemanden aus seiner Umgebung heraus.
     */
    public function test_ohne_gespeicherten_ausschnitt_bleibt_die_leiste_stehen(): void
    {
        [$user, $organization, $project] = $this->context();

        // Die Leiste nimmt nur Umgebungen an, die es im Projekt gibt — sonst
        // fällt die Angabe heraus, und der Link zeigte hier eine Wirkung, die
        // die Seite selbst nicht hat.
        Environment::factory()->for($project)->create(['name' => 'staging']);

        SavedQuery::factory()->for($organization)->for($user)->create(['name' => 'Alles']);

        $response = $this->actingAs($user)->get(route('discover.index', [
            'organization' => $organization,
            'projects' => ['webshop'],
            'environment' => 'staging',
            'period' => FilterPeriod::Last30Days->value,
            'tz' => 'UTC',
        ]));

        $href = $response->viewData('page')['props']['saved']['items'][0]['href'];

        $parameters = [];
        parse_str((string) parse_url($href, PHP_URL_QUERY), $parameters);

        $this->assertSame('staging', $parameters['environment']);
        $this->assertSame(FilterPeriod::Last30Days->value, $parameters['period']);
        $this->assertSame(['webshop'], $parameters['projects']);
    }

    public function test_eine_auswertung_wird_geaendert(): void
    {
        [$user, $organization] = $this->context();

        $saved = SavedQuery::factory()->for($organization)->for($user)->create(['name' => 'Alt']);

        $this->actingAs($user)
            ->patch(route('discover.saved.update', [$organization, $saved]), $this->payload([
                'name' => 'Neu',
                'shared' => true,
            ]))
            ->assertRedirect();

        $saved->refresh();

        $this->assertSame('Neu', $saved->name);
        $this->assertTrue($saved->shared);
        $this->assertSame(['browser.name'], $saved->discoverQuery()->fields);
    }

    public function test_eine_auswertung_wird_geloescht(): void
    {
        [$user, $organization] = $this->context();

        $saved = SavedQuery::factory()->for($organization)->for($user)->create();

        $this->actingAs($user)
            ->delete(route('discover.saved.destroy', [$organization, $saved]))
            ->assertRedirect();

        $this->assertDatabaseCount('saved_queries', 0);
    }

    /**
     * Freigeben heißt sehen, nicht ändern — und schon gar nicht löschen.
     */
    public function test_eine_freigegebene_auswertung_darf_nur_ihr_ersteller_aendern(): void
    {
        [$owner, $organization] = $this->context();

        $other = User::factory()->create();
        $organization->setRole($other, OrganizationRole::Member);
        $other->switchOrganization($organization);

        $saved = SavedQuery::factory()->for($organization)->for($owner)->shared()->create();

        $this->actingAs($other)
            ->patch(route('discover.saved.update', [$organization, $saved]), $this->payload())
            ->assertForbidden();

        $this->actingAs($other)
            ->delete(route('discover.saved.destroy', [$organization, $saved]))
            ->assertForbidden();
    }

    /**
     * Sichtbar ist, was einem selbst gehört — und was freigegeben ist.
     */
    public function test_die_leiste_zeigt_eigene_und_freigegebene(): void
    {
        [$viewer, $organization] = $this->context();

        $other = User::factory()->create();
        $organization->setRole($other, OrganizationRole::Member);

        SavedQuery::factory()->for($organization)->for($viewer)->create(['name' => 'Meine']);
        SavedQuery::factory()->for($organization)->for($other)->shared()->create(['name' => 'Geteilte']);
        SavedQuery::factory()->for($organization)->for($other)->create(['name' => 'Fremde private']);

        $this->actingAs($viewer)
            ->get(route('discover.index', ['organization' => $organization, 'tz' => 'UTC']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('saved.items', 2)
                ->where('saved.items.0.name', 'Geteilte')
                ->where('saved.items.0.own', false)
                ->where('saved.items.1.name', 'Meine')
                ->where('saved.items.1.own', true));
    }

    /**
     * Duplizieren darf, wer sie sehen darf — die Kopie gehört dem
     * Duplizierenden und ist nicht freigegeben.
     */
    public function test_eine_fremde_auswertung_laesst_sich_duplizieren(): void
    {
        [$owner, $organization] = $this->context();

        $other = User::factory()->create();
        $organization->setRole($other, OrganizationRole::Member);
        $other->switchOrganization($organization);

        $saved = SavedQuery::factory()->for($organization)->for($owner)->shared()->create([
            'name' => 'Fehler nach Browser',
        ]);

        $this->actingAs($other)
            ->post(route('discover.saved.duplicate', [$organization, $saved]))
            ->assertRedirect();

        $copy = SavedQuery::query()->where('user_id', $other->id)->firstOrFail();

        $this->assertSame('Fehler nach Browser (Kopie)', $copy->name);
        $this->assertFalse($copy->shared);
        $this->assertSame($saved->query, $copy->query);
        $this->assertSame($saved->filters, $copy->filters);
    }

    /**
     * Eine private Auswertung eines Kollegen gibt es für andere nicht.
     */
    public function test_eine_private_fremde_auswertung_laesst_sich_nicht_duplizieren(): void
    {
        [$owner, $organization] = $this->context();

        $other = User::factory()->create();
        $organization->setRole($other, OrganizationRole::Member);
        $other->switchOrganization($organization);

        $saved = SavedQuery::factory()->for($organization)->for($owner)->create();

        $this->actingAs($other)
            ->post(route('discover.saved.duplicate', [$organization, $saved]))
            ->assertForbidden();
    }

    /**
     * Der Kern der Aufgabe: mit einem Klick auf ein Dashboard.
     *
     * Die Kachel bekommt die Frage als Kopie — und **keine** eigene Sicht auf
     * die Filterleiste, damit das Dashboard seinen Zeitraum behält.
     */
    public function test_eine_auswertung_wird_als_kachel_uebernommen(): void
    {
        [$user, $organization] = $this->context();

        $saved = SavedQuery::factory()->for($organization)->for($user)->create([
            'name' => 'Fehler nach Browser',
            'query' => [
                'dataset' => 'errors',
                'fields' => ['browser.name'],
                'metrics' => ['count()'],
                'q' => 'level:error',
                'sort' => '-count',
                'limit' => 25,
                'interval' => '1h',
            ],
            'filters' => [
                'period' => FilterPeriod::Last7Days->value,
                'from' => null,
                'to' => null,
                'environment' => 'production',
                'project' => 'webshop',
            ],
        ]);

        $dashboard = Dashboard::factory()->for($organization)->for($user)->create();

        $this->actingAs($user)
            ->post(route('discover.saved.widget', [$organization, $saved]), [
                'dashboard' => $dashboard->id,
            ])
            ->assertRedirect(route('dashboards.show', [$organization, $dashboard]));

        $widget = DashboardWidget::query()->firstOrFail();

        $this->assertSame($dashboard->id, $widget->dashboard_id);
        $this->assertSame('Fehler nach Browser', $widget->title);
        $this->assertSame(WidgetType::Table, $widget->type);
        $this->assertSame($saved->query, $widget->query);

        // Der gespeicherte Ausschnitt geht ausdrücklich nicht mit: auf dem
        // Dashboard gilt dessen Filterleiste.
        $this->assertNull($widget->overrides);
    }

    /**
     * Auf ein fremdes Dashboard lässt sich nichts legen — auch nicht auf ein
     * freigegebenes: eine Kachel anzulegen ist eine Änderung daran.
     */
    public function test_auf_ein_fremdes_dashboard_laesst_sich_nichts_uebernehmen(): void
    {
        [$user, $organization] = $this->context();

        $other = User::factory()->create();
        $organization->setRole($other, OrganizationRole::Member);

        $saved = SavedQuery::factory()->for($organization)->for($user)->create();
        $dashboard = Dashboard::factory()->for($organization)->for($other)->shared()->create();

        $this->actingAs($user)
            ->post(route('discover.saved.widget', [$organization, $saved]), [
                'dashboard' => $dashboard->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('dashboard_widgets', 0);
    }

    /**
     * Ein Dashboard einer anderen Organisation ist hier nicht „verboten",
     * sondern nicht vorhanden.
     */
    public function test_ein_dashboard_einer_anderen_organisation_gibt_es_hier_nicht(): void
    {
        [$user, $organization] = $this->context();

        $elsewhere = Organization::factory()->withMember($user)->create();
        $dashboard = Dashboard::factory()->for($elsewhere)->for($user)->create();

        $saved = SavedQuery::factory()->for($organization)->for($user)->create();

        $this->actingAs($user)
            ->post(route('discover.saved.widget', [$organization, $saved]), [
                'dashboard' => $dashboard->id,
            ])
            ->assertNotFound();
    }

    public function test_zwei_gleiche_namen_im_eigenen_bestand_gehen_nicht(): void
    {
        [$user, $organization] = $this->context();

        SavedQuery::factory()->for($organization)->for($user)->create(['name' => 'Fehler nach Browser']);

        $this->actingAs($user)
            ->post(route('discover.saved.store', $organization), $this->payload())
            ->assertSessionHasErrors('name');
    }

    public function test_die_grenze_je_konto_und_organisation_gilt(): void
    {
        [$user, $organization] = $this->context();

        SavedQuery::factory()
            ->count(SavedQuery::MAX_PER_USER)
            ->for($organization)
            ->for($user)
            ->create();

        $this->actingAs($user)
            ->post(route('discover.saved.store', $organization), $this->payload())
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('saved_queries', SavedQuery::MAX_PER_USER);
    }
}
