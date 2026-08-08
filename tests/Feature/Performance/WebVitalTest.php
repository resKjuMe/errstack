<?php

namespace Tests\Feature\Performance;

use App\Enums\WebVital;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\User;
use App\Models\WebVitalAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Das Ladeerlebnis im Browser: die Übersicht der schlechtesten Seiten und die
 * Detailansicht einer einzelnen.
 *
 * Geprüft wird beides — was dasteht und wie es zustande kommt. Die drei
 * Zusagen, an denen diese Seiten hängen:
 *
 *   1. Die Bewertung folgt den Schwellen der Spezifikation und ist exakt.
 *   2. Seiten ohne Messwerte verschwinden nicht, sondern werden als solche
 *      gekennzeichnet — das ist die Auskunft, die man sonst nie bekäme.
 *   3. Die Kennzahlen kommen aus der Vorberechnung; die Zahl der Abfragen hängt
 *      nicht an der Datenmenge.
 */
class WebVitalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein fester Zeitpunkt, damit „letzte 24 Stunden" ein bekannter Ausschnitt
     * ist und nicht von der Uhr des Testrechners abhängt.
     */
    private const NOW = '2026-08-07 12:00:00';

    /** Innerhalb des Standard-Zeitraums. */
    private const INSIDE = '2026-08-07 11:00:00';

    /** Zwei Tage her — außerhalb jedes Standard-Zeitraums. */
    private const OUTSIDE = '2026-08-05 11:00:00';

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

    public function test_the_overview_shows_a_page_with_its_vitals(): void
    {
        [$user, $project] = $this->context();

        $this->vital($project, '/checkout', WebVital::Lcp, 3_200_000, 10);
        $this->vital($project, '/checkout', WebVital::Cls, 50_000, 10);

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertCount(1, $rows);
        $this->assertSame('/checkout', $rows[0]['name']);
        $this->assertTrue($rows[0]['hasData']);
        $this->assertSame(10, $rows[0]['measurements']);

        // 3,2 s liegen zwischen den Schwellen 2,5 s und 4 s.
        $this->assertSame('needs_improvement', $rows[0]['vitals']['lcp']['rating']);
        $this->assertSame('good', $rows[0]['vitals']['cls']['rating']);

        // Die Bewertung der Seite ist die schlechteste ihrer Kernwerte: eine
        // Seite, die in einem Punkt versagt, ist keine gute Seite.
        $this->assertSame('needs_improvement', $rows[0]['rating']);

        // Ein Messwert, für den nichts gemeldet wurde, steht trotzdem in der
        // Zeile — sonst wäre nicht zu unterscheiden, ob die Spalte fehlt oder
        // die Seite gut ist.
        $this->assertSame(0, $rows[0]['vitals']['inp']['count']);
        $this->assertNull($rows[0]['vitals']['inp']['rating']);
    }

    public function test_the_displayed_value_never_contradicts_its_rating(): void
    {
        [$user, $project] = $this->context();

        // Ein Wert knapp unter der Schwelle: genau der Fall, in dem eine
        // Verteilung mit groben Klassen aus einer guten Seite eine mäßige
        // machen würde.
        $this->vital($project, '/knapp', WebVital::Lcp, 2_400_000, 12);

        $rows = $this->rows($this->actingAs($user)->get($this->url()));
        $lcp = $rows[0]['vitals']['lcp'];

        $this->assertSame('good', $lcp['rating']);
        $this->assertLessThanOrEqual(WebVital::Lcp->goodMax(), $lcp['value']);
    }

    public function test_the_worst_pages_stand_on_top(): void
    {
        [$user, $project] = $this->context();

        // Wenige schlechte gegen viele mäßige: die Rangfolge zählt Erlebnisse,
        // nicht Seiten. Zehn schlechte wiegen zwanzig, fünfzig mäßige wiegen
        // fünfzig — die zweite Seite steht oben.
        $this->vital($project, '/wenige-schlechte', WebVital::Lcp, 9_000_000, 10);
        $this->vital($project, '/viele-maessige', WebVital::Lcp, 3_000_000, 50);
        $this->vital($project, '/gute', WebVital::Lcp, 900_000, 1_000);

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(
            ['/viele-maessige', '/wenige-schlechte', '/gute'],
            array_column($rows, 'name'),
        );
    }

    public function test_a_page_without_measurements_is_marked_instead_of_hidden(): void
    {
        [$user, $project] = $this->context();

        $this->vital($project, '/gemessen', WebVital::Lcp, 1_000_000, 5);

        // Eine Seite, für die es Ladevorgänge gibt, deren SDK aber keine
        // Messwerte schickt.
        TransactionAggregate::factory()
            ->for($project)
            ->named('/ohne-sdk', 'pageload')
            ->measuring(800_000, 40)
            ->at(self::INSIDE)
            ->create();

        // Ein serverseitiger Endpunkt gehört dagegen nicht auf diese Seite: für
        // ihn fehlen keine Browser-Messwerte, es gibt sie dort schlicht nicht.
        TransactionAggregate::factory()
            ->for($project)
            ->named('GET /api/preise', 'http.server')
            ->measuring(30_000, 500)
            ->at(self::INSIDE)
            ->create();

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $names = array_column($rows, 'name');

        $this->assertContains('/ohne-sdk', $names);
        $this->assertNotContains('GET /api/preise', $names);

        $without = $rows[array_search('/ohne-sdk', $names, true)];

        $this->assertFalse($without['hasData']);
        $this->assertNull($without['rating']);
        $this->assertSame(0, $without['measurements']);
    }

    public function test_only_the_chosen_period_and_environment_count(): void
    {
        [$user, $project] = $this->context();

        // Nur sichtbare Umgebungen lassen sich wählen — ohne diese Zeilen
        // übergeht der Filter die Angabe und zeigte beide.
        Environment::factory()->for($project)->create(['name' => 'production']);
        Environment::factory()->for($project)->create(['name' => 'staging']);

        $this->vital($project, '/checkout', WebVital::Lcp, 1_000_000, 3, self::INSIDE);
        $this->vital($project, '/checkout', WebVital::Lcp, 9_000_000, 99, self::OUTSIDE);

        WebVitalAggregate::factory()
            ->for($project)
            ->named('/checkout')
            ->measuring(WebVital::Lcp, 9_000_000, 99)
            ->inEnvironment('staging')
            ->at(self::INSIDE)
            ->create();

        $rows = $this->rows($this->actingAs($user)->get($this->url(['environment' => 'production'])));

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['vitals']['lcp']['count']);
    }

    public function test_the_search_matches_part_of_a_page_name(): void
    {
        [$user, $project] = $this->context();

        $this->vital($project, '/checkout/zahlung', WebVital::Lcp, 1_000_000, 5);
        $this->vital($project, '/konto', WebVital::Lcp, 1_000_000, 5);

        $rows = $this->rows($this->actingAs($user)->get($this->url(['q' => 'checkout'])));

        $this->assertSame(['/checkout/zahlung'], array_column($rows, 'name'));
    }

    public function test_the_detail_page_breaks_the_measurement_down_by_device_and_browser(): void
    {
        [$user, $project] = $this->context();

        $this->vital($project, '/checkout', WebVital::Lcp, 5_000_000, 6);

        // Die Aufschlüsselung liest die Einzelmessungen — sie ist die einzige
        // Auskunft dieser Seite, die nicht aus der Vorberechnung stammt.
        $this->measured($project, '/checkout', 6_000_000, 'Chrome', 'Pixel 8', 'DE');
        $this->measured($project, '/checkout', 6_100_000, 'Chrome', 'Pixel 8', 'DE');
        $this->measured($project, '/checkout', 6_200_000, 'Chrome', 'Pixel 8', 'AT');
        $this->measured($project, '/checkout', 900_000, 'Firefox', 'Mac', 'DE');
        $this->measured($project, '/checkout', 950_000, 'Firefox', 'Mac', 'DE');
        $this->measured($project, '/checkout', 980_000, 'Firefox', 'Mac', 'DE');

        $props = $this->props($this->actingAs($user)->get($this->url([
            'name' => '/checkout',
        ], '/ladeerlebnis/seite')));

        $detail = $props['detail'];

        $this->assertSame('/checkout', $detail['name']);
        $this->assertSame('lcp', $detail['selected']);
        $this->assertTrue($detail['hasData']);
        $this->assertSame(6, $detail['sampledTransactions']);

        $facets = self::keyed($detail['facets'], 'key');

        $this->assertArrayHasKey('device', $facets);
        $this->assertArrayHasKey('browser', $facets);

        $devices = self::keyed($facets['device']['values'], 'value');

        // Das Handy ist schlecht, der Rechner ist gut — genau die Auskunft, die
        // ein Gesamtwert verschweigt.
        $this->assertSame('poor', $devices['Pixel 8']['rating']);
        $this->assertSame('good', $devices['Mac']['rating']);
    }

    public function test_the_detail_page_can_switch_the_measurement(): void
    {
        [$user, $project] = $this->context();

        $this->vital($project, '/checkout', WebVital::Lcp, 1_000_000, 5);
        $this->vital($project, '/checkout', WebVital::Cls, 400_000, 5);

        $props = $this->props($this->actingAs($user)->get($this->url([
            'name' => '/checkout',
            'vital' => 'cls',
        ], '/ladeerlebnis/seite')));

        $this->assertSame('cls', $props['detail']['selected']);
        $this->assertSame('poor', $props['detail']['vitals']['cls']['rating']);

        // Der Verlauf gilt dem ausgewählten Messwert — sechs Grafiken
        // nebeneinander beantworten keine Frage, die eine nicht schon
        // beantwortet.
        $points = $props['detail']['series']['points'];

        $this->assertNotEmpty($points);
        $this->assertSame('poor', $points[0]['rating']);
    }

    public function test_a_page_without_measurements_shows_its_empty_state(): void
    {
        [$user] = $this->context();

        $props = $this->props($this->actingAs($user)->get($this->url([
            'name' => '/gibt-es-nicht',
        ], '/ladeerlebnis/seite')));

        // Kein Fehler 404: der Name ist keine Datenzeile, sondern eine
        // Gruppierung, und ein Link auf „letzte 24 Stunden" ist morgen leer,
        // ohne falsch zu sein.
        $this->assertFalse($props['detail']['hasData']);
    }

    public function test_the_ingest_writes_the_measurements_into_their_windows(): void
    {
        [, $project] = $this->context();

        $transaction = Transaction::factory()
            ->for($project)
            ->inBrowser(['lcp' => 2400, 'cls' => 0.05, 'frames_slow' => 3])
            ->create(['name' => '/checkout', 'started_at' => self::INSIDE]);

        WebVitalAggregate::record($transaction);

        $aggregates = WebVitalAggregate::query()->get()->keyBy('vital');

        // Zwei Zeilen und nicht drei: was zu keinem bewerteten Messwert gehört,
        // läuft nicht in die Auswertung.
        $this->assertSame(['lcp', 'cls'], $aggregates->keys()->all());
        $this->assertSame(2_400_000, $aggregates['lcp']->value_sum);
        $this->assertSame(1, $aggregates['lcp']->good_count);
        $this->assertSame(Transaction::windowFor(CarbonImmutable::parse(self::INSIDE))->timestamp,
            $aggregates['lcp']->window_start->timestamp);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_data(): void
    {
        [$user, $project] = $this->context();

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->fill($project, 5);

        // Aufwärmrunde: der erste Aufruf richtet Nebensächliches ein (Sitzung,
        // Routen), das beim zweiten schon steht.
        $this->actingAs($user)->get($this->url())->assertOk();

        $queries = [];
        $this->actingAs($user)->get($this->url())->assertOk();
        /** @var list<string> $small */
        $small = $queries;

        $this->fill($project, 60, 5);

        $queries = [];
        $this->actingAs($user)->get($this->url())->assertOk();
        /** @var list<string> $large */
        $large = $queries;

        $this->assertSame(
            count($small),
            count($large),
            'Die Zahl der Abfragen hängt an der Datenmenge: '.implode(' | ', $large),
        );

        // Der eingefasste Name trifft `transactions` und nicht
        // `transaction_aggregates` — so schreibt SQLite die Bezeichner.
        $this->assertSame(
            [],
            array_values(array_filter($large, static fn (string $sql): bool => str_contains($sql, '"transactions"'))),
            'Die Übersicht fragt die Einzelmessungen ab, statt aus den Vorberechnungen zu lesen.',
        );
    }

    public function test_the_page_hangs_in_the_navigation(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->get($this->url());

        $this->assertSame('performance/WebVitals', $this->page($response)['component']);

        $props = $this->props($response);

        // Die Spalten kommen vom Server, in der fachlichen Reihenfolge: erst die
        // Kernwerte, dann die erklärenden.
        $this->assertSame(WebVital::values(), array_column($props['vitals'], 'key'));
        $this->assertSame([true, true, true, false, false, false], array_column($props['vitals'], 'core'));

        // Die Navigation kommt seit U1 nach Gruppen sortiert (ShellData::nav);
        // für diese Prüfung zählt nur, dass der Eintrag überhaupt darin steht.
        $shell = $props['shell'];
        $links = [];

        foreach (is_array($shell) ? ($shell['nav'] ?? []) : [] as $group) {
            foreach (is_array($group) ? ($group['links'] ?? []) : [] as $link) {
                $links[] = $link;
            }
        }

        $this->assertContains(
            route('web-vitals.index'),
            array_column($links, 'href'),
        );
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
     * Ein vorberechnetes Zeitfenster mit lauter gleich großen Messungen.
     */
    private function vital(
        Project $project,
        string $name,
        WebVital $vital,
        int $value,
        int $count,
        string $at = self::INSIDE,
    ): void {
        WebVitalAggregate::factory()
            ->for($project)
            ->named($name)
            ->measuring($vital, $value, $count)
            ->at($at)
            ->create();
    }

    /**
     * Eine Einzelmessung, wie die Aufnahme sie hinterlässt — die Grundlage der
     * Aufschlüsselung.
     */
    private function measured(
        Project $project,
        string $name,
        int $lcpValue,
        string $browser,
        string $device,
        string $country,
    ): void {
        Transaction::factory()
            ->for($project)
            ->inBrowser(['lcp' => $lcpValue / 1000], $browser, $device, $country)
            ->create(['name' => $name, 'started_at' => self::INSIDE]);
    }

    /**
     * Füllt die Vorberechnung mit Seiten, damit sich die Zahl der Abfragen
     * gegen zwei Datenmengen vergleichen lässt.
     */
    private function fill(Project $project, int $pages, int $offset = 0): void
    {
        for ($index = $offset; $index < $offset + $pages; $index++) {
            foreach (WebVital::core() as $vital) {
                $this->vital($project, '/seite-'.$index, $vital, 1_000_000 + $index * 1_000, 3);
            }
        }
    }

    /**
     * Die Adresse einer der beiden Seiten. Die Zeitzone steht immer dabei, damit
     * der aufgelöste Zeitraum nicht von der Einstellung der Anwendung abhängt.
     *
     * @param  array<string, mixed>  $query
     */
    private function url(array $query = [], string $path = '/ladeerlebnis'): string
    {
        return $path.'?'.http_build_query($query + ['tz' => 'UTC']);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function page(TestResponse $response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');

        /** @var array<string, mixed> $page */
        $page = is_array($page) ? $page : [];

        return $page;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $props = $this->page($response)['props'] ?? [];

        /** @var array<string, mixed> $props */
        $props = is_array($props) ? $props : [];

        return $props;
    }

    /**
     * Eine Liste aus der Nutzlast, nach einem ihrer Felder aufgeschlüsselt.
     *
     * Von Hand und nicht über `collect()`: die Nutzlast einer Inertia-Antwort ist
     * für die statische Analyse `mixed`, und eine Sammlung darüber hätte keinen
     * Typ, den sie auflösen könnte.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(mixed $rows, string $key): array
    {
        $keyed = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row[$key]) && is_scalar($row[$key])) {
                $keyed[(string) $row[$key]] = $row;
            }
        }

        return $keyed;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        $rows = $this->props($response)['rows'] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($rows) ? array_values($rows) : [];
    }
}
