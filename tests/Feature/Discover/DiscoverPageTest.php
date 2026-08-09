<?php

namespace Tests\Feature\Discover;

use App\Models\Environment;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Die Oberfläche der freien Auswertung: die Seite, die CSV-Datei und der Weg von
 * einer Zeile zu den Ereignissen dahinter.
 *
 * Gerechnet wird hier nichts nachgeprüft — das ist die Aufgabe von
 * {@see DiscoverEngineTest}. Geprüft wird, was die Seite daraus macht: dass
 * Tabelle und Diagramm **dieselbe** Abfrage zeigen, dass der Zustand vollständig
 * aus der Adresszeile kommt und wieder dorthin zurückgeht, und dass die Datei
 * genau die Spalten und Zeilen enthält, die daneben auf dem Bildschirm stehen.
 */
class DiscoverPageTest extends TestCase
{
    use RefreshDatabase;

    /** Ein fester Zeitpunkt: „letzte 24 Stunden" soll ein bekannter Ausschnitt sein. */
    private const NOW = '2026-08-07 12:00:00';

    private const INSIDE = '2026-08-07 11:00:00';

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
     * @return array{User, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $project];
    }

    /**
     * Die Adresse der Seite. Die Zeitzone steht immer dabei, damit der aufgelöste
     * Zeitraum nicht von der Einstellung der Anwendung abhängt.
     *
     * @param  array<string, mixed>  $query
     */
    private function url(array $query = []): string
    {
        return route('discover.index', $query + ['tz' => 'UTC']);
    }

    private function event(Project $project, string $browser, string $version, string $level = 'error'): Event
    {
        return Event::factory()->for($project)->create([
            'occurred_at' => self::INSIDE,
            'received_at' => self::INSIDE,
            'level' => $level,
            'contexts' => ['browser' => ['name' => $browser, 'version' => $version]],
        ]);
    }

    public function test_it_groups_and_counts_over_the_selected_dataset(): void
    {
        [$user, $project] = $this->context();

        $this->event($project, 'Chrome', '124');
        $this->event($project, 'Chrome', '124');
        $this->event($project, 'Firefox', '126');

        $this->actingAs($user)
            ->get($this->url(['projects' => [$project->slug], 'fields' => ['browser'], 'metrics' => ['count()']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('discover/Index')
                ->where('columns.0.key', 'browser')
                ->where('columns.1.key', 'count')
                ->where('table.rows.0.groups.browser', 'Chrome 124')
                ->where('table.rows.0.values.count', 2.0)
                ->where('table.rows.1.groups.browser', 'Firefox 126')
                ->where('table.rows.1.values.count', 1.0)
            );
    }

    /**
     * Tabelle und Diagramm sind dieselbe Abfrage: die Linien sind die Zeilen, und
     * die Summe einer Linie ist die Zahl daneben.
     *
     * Das ist die Zusage, an der die ganze Seite hängt — zwei Ansichten
     * derselben Frage, die sich widersprechen, sind schlimmer als eine.
     */
    public function test_chart_and_table_show_the_same_numbers(): void
    {
        [$user, $project] = $this->context();

        $this->event($project, 'Chrome', '124');
        $this->event($project, 'Chrome', '124');
        $this->event($project, 'Firefox', '126');

        $response = $this->actingAs($user)
            ->get($this->url(['projects' => [$project->slug], 'fields' => ['browser'], 'metrics' => ['count()']]))
            ->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame(
            array_map(static fn (array $row): string => (string) $row['groups']['browser'], $props['table']['rows']),
            array_map(static fn (array $line): string => $line['label'], $props['series']['lines']),
            'Die Linien des Diagramms sind nicht die Zeilen der Tabelle.',
        );

        foreach ($props['series']['lines'] as $index => $line) {
            $this->assertSame(
                $props['table']['rows'][$index]['values']['count'],
                array_sum(array_map(static fn (?float $value): float => (float) $value, $line['values']['count'])),
                'Die Summe der Linie ist nicht die Zahl der Tabellenzeile.',
            );
        }
    }

    /**
     * Der ganze Abfragezustand steht in der Adresszeile — und kommt von dort
     * zurück in die Felder der Leiste.
     */
    public function test_the_query_state_round_trips_through_the_address(): void
    {
        [$user, $project] = $this->context();

        $this->actingAs($user)
            ->get($this->url([
                'projects' => [$project->slug],
                'dataset' => 'transactions',
                'fields' => ['name'],
                'metrics' => ['p95(duration)', 'count()'],
                'q' => 'environment:production',
                'sort' => '-p95_duration',
                'limit' => 10,
                'interval' => '1h',
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('query.dataset', 'transactions')
                ->where('query.fields', ['name'])
                ->where('query.metrics', ['p95(duration)', 'count()'])
                ->where('query.q', 'environment:production')
                ->where('query.sort', '-p95_duration')
                ->where('query.limit', 10)
                ->where('query.interval', '1h')
                ->where('series.interval', '1h')
            );
    }

    /**
     * Die Umgebung der Filterleiste schränkt die Auswertung ein — über die
     * Suchsprache und nicht über einen zweiten Weg daneben.
     */
    public function test_the_environment_of_the_filter_bar_narrows_the_analysis(): void
    {
        [$user, $project] = $this->context();

        // Die Leiste bietet nur Umgebungen an, die es als Eintrag gibt — eine
        // Auswahl auf einen bloßen Textwert in einer Meldung wäre keine.
        Environment::factory()->for($project)->create(['name' => 'production']);

        $this->event($project, 'Chrome', '124');
        Event::factory()->for($project)->create([
            'occurred_at' => self::INSIDE,
            'received_at' => self::INSIDE,
            'environment' => 'staging',
            'contexts' => ['browser' => ['name' => 'Chrome', 'version' => '124']],
        ]);

        $this->actingAs($user)
            ->get($this->url([
                'projects' => [$project->slug],
                'environment' => 'production',
                'metrics' => ['count()'],
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.values.count', 1.0));
    }

    /**
     * Eine Zeile führt zu den Ereignissen dahinter — in derselben Suchsprache,
     * damit die Liste dort genau diese Menge zeigt.
     */
    public function test_a_row_links_to_the_underlying_events(): void
    {
        [$user, $project] = $this->context();

        $this->event($project, 'Chrome', '124');

        $response = $this->actingAs($user)
            ->get($this->url([
                'projects' => [$project->slug],
                'fields' => ['browser'],
                'metrics' => ['count()'],
                'q' => 'level:error',
            ]))
            ->assertOk();

        $href = $response->viewData('page')['props']['table']['rows'][0]['href'];

        $this->assertNotNull($href);
        $this->assertStringContainsString(urlencode('level:error browser:"Chrome 124"'), $href);
        $this->assertStringContainsString(urlencode($project->slug), $href);
    }

    /**
     * Ein Gruppenwert, den es nicht gibt, lässt sich nicht als Bedingung
     * schreiben — dann gibt es lieber keinen Link als einen, der eine andere
     * Menge zeigt.
     */
    public function test_a_row_without_a_group_value_has_no_link(): void
    {
        [$user, $project] = $this->context();

        Event::factory()->for($project)->create([
            'occurred_at' => self::INSIDE,
            'received_at' => self::INSIDE,
            'contexts' => null,
        ]);

        $this->actingAs($user)
            ->get($this->url(['projects' => [$project->slug], 'fields' => ['browser'], 'metrics' => ['count()']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows.0.groups.browser', null)
                ->where('table.rows.0.href', null)
            );
    }

    /**
     * Die Antwortzeiten haben ihre eigene, kleine Suche: was sich dort nicht
     * schreiben lässt, bekommt keinen Link.
     */
    public function test_a_transaction_row_only_links_where_the_target_understands_it(): void
    {
        [$user, $project] = $this->context();

        Transaction::factory()->for($project)->create([
            'started_at' => self::INSIDE,
            'finished_at' => self::INSIDE,
            'name' => 'GET /checkout',
            'browser' => 'Chrome',
        ]);

        $this->actingAs($user)
            ->get($this->url([
                'projects' => [$project->slug],
                'dataset' => 'transactions',
                'fields' => ['name'],
                'metrics' => ['count()'],
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->whereNot('table.rows.0.href', null));

        $this->actingAs($user)
            ->get($this->url([
                'projects' => [$project->slug],
                'dataset' => 'transactions',
                'fields' => ['browser'],
                'metrics' => ['count()'],
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.href', null));
    }

    /**
     * Die Datei enthält genau die angezeigten Spalten — und genau die Zeilen, die
     * dieselbe Abfrage auf der Seite zeigt.
     */
    public function test_the_export_contains_exactly_the_displayed_columns_and_rows(): void
    {
        [$user, $project] = $this->context();

        $this->event($project, 'Chrome', '124');
        $this->event($project, 'Chrome', '124');
        $this->event($project, 'Firefox', '126');

        $query = [
            'projects' => [$project->slug],
            'fields' => ['browser'],
            'metrics' => ['count()'],
            'tz' => 'UTC',
        ];

        $csv = $this->actingAs($user)
            ->get(route('discover.export', $query))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", str_replace(["\xEF\xBB\xBF", "\r"], '', $csv))));

        $this->assertSame(['browser;Anzahl', 'Chrome 124;2', 'Firefox 126;1'], $lines);
    }

    /**
     * Die Zeilenzahl der Abfrage gilt auch für die Datei: wer 1 Zeile sieht,
     * exportiert 1 Zeile — sonst wären es zwei Antworten auf dieselbe Frage.
     */
    public function test_the_export_honours_the_row_limit_of_the_query(): void
    {
        [$user, $project] = $this->context();

        $this->event($project, 'Chrome', '124');
        $this->event($project, 'Firefox', '126');

        $csv = $this->actingAs($user)
            ->get(route('discover.export', [
                'projects' => [$project->slug],
                'fields' => ['browser'],
                'metrics' => ['count()'],
                'limit' => 1,
                'tz' => 'UTC',
            ]))
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", str_replace(["\xEF\xBB\xBF", "\r"], '', $csv))));

        $this->assertCount(2, $lines, 'Kopfzeile und genau eine Datenzeile.');
    }

    /**
     * Eine abgelehnte Abfrage ist eine Auskunft: Grenze und verlangter Wert
     * stehen an der Seite, und die Seite selbst bleibt bedienbar.
     */
    public function test_a_rejected_query_explains_itself_instead_of_failing(): void
    {
        [$user, $project] = $this->context();

        $this->actingAs($user)
            ->get($this->url([
                'projects' => [$project->slug],
                'fields' => ['browser', 'os', 'level', 'platform'],
                'metrics' => ['count()'],
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('error.reason', 'limit')
                ->where('error.context.limit', 'group_fields')
                ->where('table', null)
            );
    }

    /**
     * Ohne genau ein Projekt wird nicht geraten: die Seite bittet um eine
     * Auswahl und bietet sie an.
     */
    public function test_it_asks_for_a_single_project_when_the_filter_names_several(): void
    {
        [$user, $project] = $this->context();

        Project::factory()->for($project->organization)->create(['slug' => 'blog']);

        $this->actingAs($user)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('project', null)
                ->where('table', null)
                ->count('projectOptions', 2)
            );
    }

    /**
     * Die Auswertung steht in der Hauptnavigation — sonst findet sie niemand,
     * der nicht den Link kennt.
     */
    public function test_the_navigation_offers_the_analysis(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $links = collect($response->viewData('page')['props']['shell']['nav'])
            ->flatMap(fn (array $group): array => $group['links'])
            ->pluck('href')
            ->all();

        $this->assertTrue(
            collect($links)->contains(fn (string $href): bool => str_contains($href, 'auswertung')),
            'Die freie Auswertung fehlt in der Navigation.',
        );
    }
}
