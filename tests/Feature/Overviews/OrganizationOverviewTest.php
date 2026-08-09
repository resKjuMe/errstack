<?php

namespace Tests\Feature\Overviews;

use App\Enums\AlertStatus;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\MetricAlert;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Übersicht der Organisation.
 *
 * Geprüft wird nicht, ob der Motor richtig rechnet — das ist Sache der Tests
 * von D1. Geprüft wird, was diese Seite dazutut: dass die Kacheln ihre eigenen
 * Adressen haben, dass die Filterleiste auf sie wirkt, dass jede Zahl einen Weg
 * hat, und dass ein Projekt ohne Meldungen einen Einrichtungs-Hinweis bekommt
 * statt einer Nulllinie.
 */
class OrganizationOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-07 12:00:00';

    private const INSIDE = '2026-08-07 11:00:00';

    /** Außerhalb der letzten 24 Stunden, innerhalb der letzten 7 Tage. */
    private const LAST_WEEK = '2026-08-04 11:00:00';

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
     * Eine Meldung, wie sie über die Aufnahme hereinkommt: die Aufnahme-Zeile
     * **und** das ausgewertete Ereignis.
     *
     * Beides, weil beide Seiten geprüft werden: die Kacheln zählen die
     * Ereignisse, und ob ein Projekt überhaupt angeschlossen ist, entscheidet
     * die Aufnahme ({@see App\Support\Overviews\OverviewSetup}). Nur ein
     * Ereignis zu erzeugen wäre ein Zustand, den es im Betrieb nicht gibt.
     */
    private function event(Project $project, string $at): Event
    {
        IngestPayload::factory()->for($project)->create();

        return Event::factory()->for($project)->create([
            'occurred_at' => $at,
            'received_at' => $at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function panel(User $user, Organization $organization, string $panel, array $query = []): array
    {
        return $this->actingAs($user)
            ->getJson(route('dashboard.panel', [$organization, 'panel' => $panel] + $query + ['tz' => 'UTC']))
            ->assertOk()
            ->json('panel');
    }

    /**
     * Die Seite liefert das Raster und keine Zahlen: jede Kachel bekommt ihre
     * eigene Adresse, damit der Browser sie nebeneinander holt.
     */
    public function test_the_page_delivers_one_address_per_panel(): void
    {
        [$user, $organization] = $this->context();

        $this->actingAs($user)
            ->get(route('dashboard', $organization))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('panels', 5)
                ->where('hasProjects', true)
            );
    }

    /**
     * Ohne ein einziges Projekt ist die Übersicht keine leere Auswertung,
     * sondern eine Organisation ohne Projekte.
     */
    public function test_an_organization_without_projects_points_to_the_projects(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $user->switchOrganization($organization);

        $this->actingAs($user)
            ->get(route('dashboard', $organization))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasProjects', false));
    }

    /**
     * Der Zeitraum der Filterleiste wirkt auf die Kacheln — er steht in der
     * Adresse der Kachel und nicht nur an der Seite.
     */
    public function test_the_filter_bar_applies_to_a_panel(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->event($project, self::INSIDE);
        $this->event($project, self::LAST_WEEK);

        $day = $this->panel($user, $organization, 'errors', ['period' => '24h', 'projects' => [$project->slug]]);
        $week = $this->panel($user, $organization, 'errors', ['period' => '7d', 'projects' => [$project->slug]]);

        // Über JSON kommt aus 1.0 eine 1 zurück — verglichen wird der Wert,
        // nicht seine Schreibweise.
        $this->assertSame(1.0, (float) $day['total']);
        $this->assertSame(2.0, (float) $week['total']);
    }

    /**
     * Zahlen aus mehreren Projekten werden addiert — der Motor rechnet je
     * Projekt, die Übersicht legt zusammen.
     */
    public function test_a_series_sums_the_selected_projects(): void
    {
        [$user, $organization, $project] = $this->context();
        $other = Project::factory()->for($organization)->create(['slug' => 'api']);

        $this->event($project, self::INSIDE);
        $this->event($other, self::INSIDE);

        $panel = $this->panel($user, $organization, 'errors', ['period' => '24h']);

        $this->assertSame(2.0, (float) $panel['total']);
    }

    /**
     * Jede Zeile der Rangliste führt weiter — und die Zahl daneben in die
     * Fehlerliste genau dieses Projekts.
     */
    public function test_every_row_of_the_project_ranking_links_further(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->event($project, self::INSIDE);

        $panel = $this->panel($user, $organization, 'projects', ['period' => '24h']);

        $this->assertCount(1, $panel['rows']);
        $this->assertStringContainsString('/uebersicht', $panel['rows'][0]['href']);
        $this->assertStringContainsString('projects%5B%5D=webshop', $panel['rows'][0]['values'][0]['href']);
    }

    /**
     * Ein Projekt ohne jede Meldung zeigt den Weg in die Einrichtung — ein
     * leeres Diagramm wäre hier die falsche Auskunft.
     */
    public function test_a_project_without_data_gets_a_setup_hint(): void
    {
        [$user, $organization] = $this->context();

        $panel = $this->panel($user, $organization, 'errors', ['period' => '24h']);

        $this->assertNotNull($panel['setup']);
        $this->assertTrue($panel['setup']['all']);
        $this->assertSame('webshop', $panel['setup']['projects'][0]['slug']);
    }

    /**
     * Sobald etwas angekommen ist, verschwindet der Hinweis — auch wenn im
     * gewählten Zeitraum nichts passiert ist. „Noch nicht angeschlossen" und
     * „gerade ruhig" sind zwei verschiedene Auskünfte.
     */
    public function test_a_quiet_project_is_not_treated_as_unconnected(): void
    {
        [$user, $organization, $project] = $this->context();

        $this->event($project, self::LAST_WEEK);

        $panel = $this->panel($user, $organization, 'errors', ['period' => '24h']);

        $this->assertNull($panel['setup']);
        $this->assertTrue($panel['empty']);
    }

    /**
     * Die Alarm-Kachel zeigt, was gerade nicht in Ordnung ist — kritisch zuerst
     * und unabhängig vom gewählten Zeitraum.
     */
    public function test_the_alert_panel_lists_open_alerts_critical_first(): void
    {
        [$user, $organization, $project] = $this->context();

        MetricAlert::factory()->for($project)->create([
            'name' => 'Warnung',
            'status' => AlertStatus::Warning,
            'status_since' => self::INSIDE,
        ]);
        MetricAlert::factory()->for($project)->create([
            'name' => 'Kritisch',
            'status' => AlertStatus::Critical,
            'status_since' => self::LAST_WEEK,
        ]);
        MetricAlert::factory()->for($project)->create([
            'name' => 'In Ordnung',
            'status' => AlertStatus::Ok,
        ]);

        $panel = $this->panel($user, $organization, 'alerts', ['period' => '24h']);

        $this->assertSame(['Kritisch', 'Warnung'], array_column($panel['rows'], 'title'));
    }

    /**
     * Eine unbekannte Kachel ist eine unbekannte Adresse und keine leere
     * Antwort: eine Kachel, die stillschweigend nichts zeigt, sieht aus wie
     * eine, in der nichts passiert ist.
     */
    public function test_an_unknown_panel_is_a_missing_address(): void
    {
        [$user, $organization] = $this->context();

        // Mit Weiterleitungen: unterhalb von `/organisationen` fängt die
        // Umzugs-Weiterleitung (U6) alles ab, was es nicht gibt, und schickt es
        // in den Einstellungsbereich — wo es dann den 404 gibt. Die Auskunft
        // „gibt es nicht" ist dieselbe, sie kommt nur eine Adresse später.
        $this->actingAs($user)
            ->followingRedirects()
            ->getJson(route('dashboard.panel', [$organization, 'panel' => 'gibtsnicht']))
            ->assertNotFound();
    }

    /**
     * Wer nicht Mitglied ist, sieht die Übersicht nicht.
     */
    public function test_a_stranger_cannot_read_a_panel(): void
    {
        [, $organization] = $this->context();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson(route('dashboard.panel', [$organization, 'panel' => 'errors']))
            ->assertForbidden();
    }
}
