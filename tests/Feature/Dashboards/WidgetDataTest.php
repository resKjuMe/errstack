<?php

namespace Tests\Feature\Dashboards;

use App\Enums\OrganizationRole;
use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Was eine Kachel zeigt — die Zahlen, die sie über ihre eigene Adresse holt.
 *
 * Gerechnet wird auch hier nichts nachgeprüft (das ist die Aufgabe der Tests des
 * Motors). Geprüft wird, dass jede Darstellungsart die Frage stellt, die zu ihr
 * gehört, dass die Filterleiste gilt — und dass eine Kachel davon abweichen
 * darf, ohne die anderen mitzunehmen.
 */
class WidgetDataTest extends TestCase
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
     * @return array{User, Organization, Project, Dashboard}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['slug' => 'webshop']);
        $dashboard = Dashboard::factory()->for($organization)->for($user)->create();

        $user->switchOrganization($organization);

        return [$user, $organization, $project, $dashboard];
    }

    private function event(Project $project, string $at, string $level = 'error'): Event
    {
        return Event::factory()->for($project)->create([
            'occurred_at' => $at,
            'received_at' => $at,
            'level' => $level,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function widget(Dashboard $dashboard, WidgetType $type, array $attributes = []): DashboardWidget
    {
        return DashboardWidget::factory()->for($dashboard)->ofType($type)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function data(User $user, Organization $organization, Dashboard $dashboard, DashboardWidget $widget, array $query = []): array
    {
        $response = $this->actingAs($user)
            ->getJson(route('dashboards.widgets.data', [$organization, $dashboard, $widget] + $query + ['tz' => 'UTC']))
            ->assertOk();

        return $response->json('widget');
    }

    /**
     * Ein Verlauf bekommt eine Zeitreihe, eine Rangliste eine Tabelle — die
     * Darstellungsart entscheidet, welche Frage der Motor überhaupt bekommt.
     */
    public function test_a_series_widget_asks_for_a_series_and_a_table_widget_for_a_table(): void
    {
        [$user, $organization, $project, $dashboard] = $this->context();

        $this->event($project, self::INSIDE);

        $line = $this->widget($dashboard, WidgetType::Line);
        $table = $this->widget($dashboard, WidgetType::Table);

        $series = $this->data($user, $organization, $dashboard, $line, ['projects' => [$project->slug]]);
        $rows = $this->data($user, $organization, $dashboard, $table, ['projects' => [$project->slug]]);

        $this->assertNotNull($series['series']);
        $this->assertNull($series['table']);

        $this->assertNotNull($rows['table']);
        $this->assertNull($rows['series']);
    }

    /**
     * Eine große Zahl liest **eine** Zeile — fünfzig zu lesen und
     * neunundvierzig wegzuwerfen wäre Arbeit für nichts.
     */
    public function test_a_big_number_reads_a_single_row(): void
    {
        [$user, $organization, $project, $dashboard] = $this->context();

        $this->event($project, self::INSIDE);
        $this->event($project, self::INSIDE);

        $widget = $this->widget($dashboard, WidgetType::BigNumber, [
            'query' => [
                'dataset' => 'errors',
                'fields' => ['level'],
                'metrics' => ['count()'],
                'q' => '',
                'sort' => '',
                'limit' => 50,
                'interval' => null,
            ],
        ]);

        $data = $this->data($user, $organization, $dashboard, $widget, ['projects' => [$project->slug]]);

        $this->assertCount(1, $data['table']['rows']);
    }

    /**
     * Der Zeitraum der Filterleiste gilt — und eine Kachel darf ihn für sich
     * überschreiben, ohne die anderen mitzunehmen.
     */
    public function test_a_widget_may_override_the_time_range(): void
    {
        [$user, $organization, $project, $dashboard] = $this->context();

        $this->event($project, self::INSIDE);
        $this->event($project, self::LAST_WEEK);

        $inherits = $this->widget($dashboard, WidgetType::Table);
        $overrides = $this->widget($dashboard, WidgetType::Table, ['overrides' => ['period' => '7d']]);

        $query = ['projects' => [$project->slug], 'period' => '24h'];

        $inherited = $this->data($user, $organization, $dashboard, $inherits, $query);
        $overridden = $this->data($user, $organization, $dashboard, $overrides, $query);

        $this->assertSame(1.0, (float) $inherited['table']['rows'][0]['values']['count']);
        $this->assertFalse($inherited['scope']['overridden']);

        $this->assertSame(2.0, (float) $overridden['table']['rows'][0]['values']['count']);
        $this->assertTrue($overridden['scope']['overridden']);
    }

    /**
     * Ohne genau ein Projekt rechnet der Motor nicht — und die Kachel rät nicht,
     * welches gemeint war. Sie sagt es, statt leer dazustehen.
     */
    public function test_without_exactly_one_project_the_widget_says_so(): void
    {
        [$user, $organization, $project, $dashboard] = $this->context();

        Project::factory()->for($organization)->create(['slug' => 'zweites']);

        $widget = $this->widget($dashboard, WidgetType::Table);

        $data = $this->data($user, $organization, $dashboard, $widget);

        $this->assertSame('project_required', $data['error']['reason']);
        $this->assertNotSame('', $data['error']['message']);

        // Mit einem Projekt in der Leiste rechnet dieselbe Kachel.
        $this->assertNull(
            $this->data($user, $organization, $dashboard, $widget, ['projects' => [$project->slug]])['error']
        );
    }

    /**
     * Zeigt eine Kachel auf ein Projekt, das es nicht mehr gibt, sagt sie das —
     * statt stillschweigend das Projekt der Leiste zu nehmen.
     */
    public function test_a_widget_pointing_at_a_vanished_project_says_so(): void
    {
        [$user, $organization, $project, $dashboard] = $this->context();

        $widget = $this->widget($dashboard, WidgetType::Table, ['overrides' => ['project' => 'gibt-es-nicht']]);

        $data = $this->data($user, $organization, $dashboard, $widget, ['projects' => [$project->slug]]);

        $this->assertSame('project_missing', $data['error']['reason']);
    }

    /**
     * Eine abgelehnte Abfrage ist eine Auskunft und kein Loch: der Motor sagt
     * mit Grund, warum er nicht gerechnet hat, und die Kachel gibt es weiter.
     *
     * Abgelehnt wird hier über die **Kennzahl** und nicht über ein unbekanntes
     * Feld: ein bloßer Name ohne Entsprechung ist im Motor kein Fehler, sondern
     * ein Merkmal ({@see AbstractDatasetFields::tagDefinition()}) — die Kachel
     * bekäme also eine leere Tabelle und keine Meldung.
     */
    public function test_a_rejected_query_is_reported_at_the_widget(): void
    {
        [$user, $organization, $project, $dashboard] = $this->context();

        $widget = $this->widget($dashboard, WidgetType::Table, [
            'query' => [
                'dataset' => 'errors',
                'fields' => [],
                'metrics' => ['gibt_es_nicht()'],
                'q' => '',
                'sort' => '',
                'limit' => 5,
                'interval' => null,
            ],
        ]);

        $data = $this->data($user, $organization, $dashboard, $widget, ['projects' => [$project->slug]]);

        $this->assertNotNull($data['error']);
        $this->assertNull($data['table']);
    }

    /**
     * Die Kachel eines fremden, nicht freigegebenen Dashboards liefert keine
     * Zahlen — die Rechteprüfung hängt am Dashboard und nicht an der Adresse
     * der Daten.
     */
    public function test_widget_data_follows_the_dashboards_permissions(): void
    {
        [$user, $organization, $project] = $this->context();

        $owner = User::factory()->create();
        $organization->setRole($owner, OrganizationRole::Member);

        $foreign = Dashboard::factory()->for($organization)->for($owner)->create();
        $widget = $this->widget($foreign, WidgetType::Table);

        $this->actingAs($user)
            ->getJson(route('dashboards.widgets.data', [$organization, $foreign, $widget, 'projects' => [$project->slug]]))
            ->assertForbidden();
    }

    /**
     * Eine Kachel, die nicht zu diesem Dashboard gehört, ist nicht „verboten",
     * sondern nicht vorhanden.
     */
    public function test_a_widget_of_another_dashboard_is_not_found(): void
    {
        [$user, $organization, , $dashboard] = $this->context();

        $other = Dashboard::factory()->for($organization)->for($user)->create();
        $widget = $this->widget($other, WidgetType::Table);

        $this->actingAs($user)
            ->getJson(route('dashboards.widgets.data', [$organization, $dashboard, $widget]))
            ->assertNotFound();
    }
}
