<?php

namespace Tests\Feature\Performance;

use App\Enums\TransactionSort;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TransactionAggregate;
use App\Models\TransactionUserAggregate;
use App\Models\User;
use App\Support\Performance\DurationHistogram;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Ingest\TransactionIngestTest;
use Tests\TestCase;

/**
 * Die Performance-Übersicht: die Liste, die sagt, wo man hinschauen soll.
 *
 * Geprüft wird beides — was dasteht und wie es zustande kommt. Die Kennzahlen
 * müssen stimmen, aber ebenso wichtig ist die Zusage, dass sie ausschließlich
 * aus den Vorberechnungen stammen: eine Seite, die dieselben Zahlen aus den
 * Einzelmessungen zusammenrechnet, sieht in einem Test mit zehn Zeilen genauso
 * aus und fällt erst im Betrieb um.
 *
 * Die Zeitfenster entstehen hier über die Factory und nicht über die Aufnahme.
 * Eine Messung durch die ganze Verarbeitungskette zu schicken, nur damit am Ende
 * eine Zahl in einem Fenster steht, prüft die Kette — und die hat ihren eigenen
 * Test ({@see TransactionIngestTest}).
 */
class PerformanceOverviewTest extends TestCase
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
        return '/leistung?'.http_build_query($query + ['tz' => 'UTC']);
    }

    /**
     * Die Nutzlast der Inertia-Seite.
     *
     * Gelesen wird sie direkt und nicht über eine Kette von
     * `assertInertia`-Zusicherungen: hier werden Zahlen geprüft, und die
     * vergleichen sich als PHP-Werte genauer und lesbarer.
     *
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
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        $rows = $this->props($response)['rows'] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Das Perzentil, das für lauter gleich lange Messungen herauskommt: die
     * Obergrenze ihrer Klasse. Berechnet und nicht hingeschrieben, damit der
     * Test nicht die Klasseneinteilung festschreibt, sondern die Auswertung.
     */
    private static function percentileOf(int $durationUs): int
    {
        return DurationHistogram::lowerBound(DurationHistogram::bucketFor($durationUs) + 1);
    }

    public function test_the_overview_shows_a_transaction_with_its_metrics(): void
    {
        [$user, $project] = $this->context();

        TransactionAggregate::factory()
            ->for($project)
            ->named('GET /checkout')
            ->measuring(1_500_000, 8, 2)
            ->at(self::INSIDE)
            ->create();

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertCount(1, $rows);
        $this->assertSame('GET /checkout', $rows[0]['name']);
        $this->assertSame('http.server', $rows[0]['op']);
        $this->assertSame(8, $rows[0]['count']);
        $this->assertSame(0.25, $rows[0]['failureRate']);
        $this->assertSame(1_500_000, $rows[0]['avgUs']);
        $this->assertSame(self::percentileOf(1_500_000), $rows[0]['p50Us']);
        $this->assertSame(self::percentileOf(1_500_000), $rows[0]['p95Us']);

        // Acht Aufrufe in 24 Stunden — der Durchsatz ist die hochgerechnete
        // Anzahl geteilt durch die Minuten des Zeitraums.
        $this->assertSame(round(8 / 1440, 4), $rows[0]['throughput']);
    }

    public function test_the_slowest_transaction_stands_on_top_and_the_direction_can_be_reversed(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 'GET /slow', 2_000_000);
        $this->aggregate($project, 'GET /fast', 20_000);

        // Die Voreinstellung ist p95 absteigend: „sortiert nach dem größten
        // Problem". Das ist der Testfall aus der Aufgabe.
        $descending = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(['GET /slow', 'GET /fast'], array_column($descending, 'name'));

        $ascending = $this->rows($this->actingAs($user)->get($this->url([
            'sort' => 'p95',
            'direction' => 'asc',
        ])));

        $this->assertSame(['GET /fast', 'GET /slow'], array_column($ascending, 'name'));
    }

    public function test_every_metric_can_be_sorted_in_both_directions(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 'GET /a', 20_000);
        $this->aggregate($project, 'GET /b', 200_000);
        $this->aggregate($project, 'GET /c', 2_000_000);

        foreach (TransactionSort::values() as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $rows = $this->rows($this->actingAs($user)->get($this->url([
                    'sort' => $sort,
                    'direction' => $direction,
                ])));

                $this->assertCount(3, $rows, "Sortierung nach „{$sort}\" ({$direction}) verliert Zeilen.");
            }
        }

        // Eine Stichprobe auf die Reihenfolge selbst: der Name ist die einzige
        // Kennzahl, deren Ordnung ohne Kenntnis der Zahlen feststeht.
        $byName = $this->rows($this->actingAs($user)->get($this->url([
            'sort' => 'name',
            'direction' => 'asc',
        ])));

        $this->assertSame(['GET /a', 'GET /b', 'GET /c'], array_column($byName, 'name'));
    }

    public function test_the_values_follow_the_chosen_period(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 'GET /recent', 100_000, at: '2026-08-07 11:30:00');
        $this->aggregate($project, 'GET /earlier', 100_000, at: '2026-08-07 05:00:00');
        $this->aggregate($project, 'GET /old', 100_000, at: self::OUTSIDE);

        // Die Voreinstellung sind 24 Stunden: die zwei jüngeren Messungen, nicht
        // die von vorgestern.
        $day = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(['GET /earlier', 'GET /recent'], self::names($day));

        // Eine Stunde: auch die ältere der beiden fällt heraus, und mit ihr ihre
        // Zeile. Das ist der Testfall aus der Aufgabe — die Werte ändern sich
        // passend zum gewählten Zeitraum.
        $hour = $this->rows($this->actingAs($user)->get($this->url(['period' => '1h'])));

        $this->assertSame(['GET /recent'], self::names($hour));
    }

    public function test_the_search_finds_a_part_of_the_name_and_an_operation(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 'GET /checkout', 100_000);
        $this->aggregate($project, 'GET /cart', 100_000);
        $this->aggregate($project, 'orders:import', 100_000, op: 'queue.task');

        $byName = $this->rows($this->actingAs($user)->get($this->url(['q' => 'checkout'])));
        $this->assertSame(['GET /checkout'], self::names($byName));

        // Groß- und Kleinschreibung sind der Suche gleichgültig.
        $upperCase = $this->rows($this->actingAs($user)->get($this->url(['q' => 'CHECKOUT'])));
        $this->assertSame(['GET /checkout'], self::names($upperCase));

        $byOp = $this->rows($this->actingAs($user)->get($this->url(['q' => 'op:queue.task'])));
        $this->assertSame(['orders:import'], self::names($byOp));

        // Zwei Begriffe werden UND-verknüpft — hier bleibt nichts übrig.
        $both = $this->rows($this->actingAs($user)->get($this->url(['q' => 'checkout op:queue.task'])));
        $this->assertSame([], self::names($both));

        // Der Freitext trifft auch die Operation.
        $freeTextOp = $this->rows($this->actingAs($user)->get($this->url(['q' => 'queue'])));
        $this->assertSame(['orders:import'], self::names($freeTextOp));
    }

    public function test_the_environment_filter_applies(): void
    {
        [$user, $project] = $this->context();

        // Nur sichtbare Umgebungen lassen sich wählen — ohne diese Zeilen
        // übergeht der Filter die Angabe und zeigte beide Zeilen.
        Environment::factory()->for($project)->create(['name' => 'production']);
        Environment::factory()->for($project)->create(['name' => 'staging']);

        $this->aggregate($project, 'GET /checkout', 100_000);
        $this->aggregate($project, 'GET /checkout', 100_000, environment: 'staging');

        $rows = $this->rows($this->actingAs($user)->get($this->url(['environment' => 'staging'])));

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['count']);
    }

    public function test_sampling_raises_the_throughput_and_leaves_the_response_times_alone(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 'GET /plain', 500_000, count: 10, failures: 2);

        TransactionAggregate::factory()
            ->for($project)
            ->named('GET /sampled')
            ->measuring(500_000, 10, 2)
            // Zehn behaltene Messungen stehen für hundert Aufrufe.
            ->extrapolating(10)
            ->at(self::INSIDE)
            ->create();

        $rows = $this->rows($this->actingAs($user)->get($this->url(['sort' => 'name', 'direction' => 'asc'])));

        [$plain, $sampled] = $rows;

        // Der Durchsatz ist hochgerechnet: zehn behaltene Messungen in 24
        // Stunden gegen hundert daraus geschätzte Aufrufe im selben Zeitraum.
        $this->assertSame(round(10 / 1440, 4), $plain['throughput']);
        $this->assertSame(round(100 / 1440, 4), $sampled['throughput']);
        $this->assertSame(100.0, $sampled['extrapolatedCount']);

        // … die Verteilung und der Anteil dagegen nicht. Sie lassen sich aus
        // einer Stichprobe unverzerrt schätzen; sie mitzustrecken würde Zähler
        // und Nenner gleichermaßen vergrößern und nichts ändern außer der
        // vorgetäuschten Genauigkeit.
        $this->assertSame($plain['p95Us'], $sampled['p95Us']);
        $this->assertSame($plain['failureRate'], $sampled['failureRate']);
        $this->assertSame($plain['count'], $sampled['count']);
    }

    public function test_the_user_count_comes_from_the_user_aggregates_and_counts_each_user_once(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 'GET /checkout', 500_000, count: 4);

        // Derselbe Nutzer in zwei Fenstern — das ist **ein** Nutzer. Genau
        // deshalb steht in der Vorberechnung eine Zeile je Nutzer und nicht nur
        // ein Zähler.
        $this->userRow($project, 'GET /checkout', 'kunde-1', self::INSIDE);
        $this->userRow($project, 'GET /checkout', 'kunde-1', '2026-08-07 11:05:00');
        $this->userRow($project, 'GET /checkout', 'kunde-2', self::INSIDE, miserable: true);

        // Ein Nutzer außerhalb des Zeitraums zählt nicht mit.
        $this->userRow($project, 'GET /checkout', 'kunde-3', self::OUTSIDE);

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(2, $rows[0]['users']);
        $this->assertSame(1, $rows[0]['miserableUsers']);
        $this->assertSame(0.5, $rows[0]['userMisery']);
    }

    public function test_a_transaction_without_users_reports_no_misery(): void
    {
        [$user, $project] = $this->context();

        // Hintergrundarbeit ohne Nutzerkennung: keine unzufriedenen Nutzer ist
        // etwas anderes als null Prozent Unzufriedenheit.
        $this->aggregate($project, 'orders:import', 5_000_000, op: 'queue.task');

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(0, $rows[0]['users']);
        $this->assertNull($rows[0]['userMisery']);
    }

    public function test_the_trend_compares_with_the_period_before(): void
    {
        [$user, $project] = $this->context();

        // Der gewählte Zeitraum ist eine Stunde; der Vorzeitraum ist die Stunde
        // davor. In beiden genug Messungen, damit der Vergleich überhaupt
        // stattfindet.
        $this->aggregate($project, 'GET /checkout', 1_000_000, count: 10, at: '2026-08-07 11:30:00');
        $this->aggregate($project, 'GET /checkout', 100_000, count: 10, at: '2026-08-07 10:30:00');

        // Eine Transaktion, die es vorher nicht gab.
        $this->aggregate($project, 'GET /neu', 1_000_000, count: 10, at: '2026-08-07 11:30:00');

        $rows = $this->rows($this->actingAs($user)->get($this->url([
            'period' => '1h',
            'sort' => 'name',
            'direction' => 'asc',
        ])));

        [$checkout, $fresh] = $rows;

        $this->assertSame('worse', $checkout['trend']);
        $this->assertIsFloat($checkout['changeRatio']);
        $this->assertGreaterThan(0.0, $checkout['changeRatio']);

        $this->assertSame('new', $fresh['trend']);
        $this->assertNull($fresh['changeRatio']);
    }

    public function test_the_project_of_another_organization_stays_invisible(): void
    {
        [$user, $project] = $this->context();
        $foreign = Project::factory()->create(['slug' => 'fremd']);

        $this->aggregate($project, 'GET /eigen', 100_000);
        $this->aggregate($foreign, 'GET /fremd', 100_000);

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(['GET /eigen'], self::names($rows));
    }

    /**
     * Die prüfbare Fassung der Zusage „bei einer Million Transaktionen unter
     * einer Sekunde".
     *
     * Eine Million Zeilen sind in der CI nicht zu erzeugen — der Aufbau allein
     * dauerte länger als die ganze Testsuite. Geprüft wird deshalb, woran die
     * Zusage hängt: die Zahl der Abfragen wächst nicht mit der Datenmenge, und
     * keine Abfrage rührt die Einzelmessungen an. Wäre eines von beiden verletzt,
     * hülfe auch die schnellste Datenbank nicht; sind beide erfüllt, kostet die
     * Seite bei zehn Fenstern dasselbe wie bei zehn Millionen — die Datenbank
     * legt sie über einen Index zusammen und liefert eine Zeile je
     * Transaktionsname.
     */
    public function test_the_number_of_queries_does_not_grow_with_the_data(): void
    {
        [$user, $project] = $this->context();

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->fillWithAggregates($project, 10, 0);

        // Aufwärmrunde: der erste Aufruf richtet Nebensächliches ein (Sitzung,
        // Routen), das beim zweiten schon steht.
        $this->actingAs($user)->get($this->url())->assertOk();

        $queries = [];
        $this->actingAs($user)->get($this->url())->assertOk();
        /** @var list<string> $small */
        $small = $queries;

        $this->fillWithAggregates($project, 190, 10);

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
        // `transaction_aggregates` — so schreibt SQLite die Bezeichner, und auf
        // SQLite läuft die Testsuite.
        $this->assertSame(
            [],
            array_values(array_filter($large, fn (string $sql): bool => str_contains($sql, '"transactions"'))),
            'Die Übersicht fragt die Einzelmessungen ab, statt aus den Vorberechnungen zu lesen.',
        );
    }

    public function test_the_page_carries_its_state_and_the_navigation_entry(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)
            ->get($this->url(['sort' => 'users', 'direction' => 'asc', 'q' => 'checkout']));

        $page = $this->page($response);
        $props = $this->props($response);

        $this->assertSame('Performance', $page['component']);
        $this->assertSame('users', $props['sort']);
        $this->assertSame('asc', $props['direction']);
        $this->assertSame('checkout', $props['q']);
        $this->assertFalse($props['truncated']);

        // Jede Spalte, die sich anklicken lässt, muss ein Sortierschlüssel sein,
        // den der Server auch annimmt. Zwei getrennte Listen wären zwei Listen,
        // die auseinanderlaufen.
        $this->assertSame(TransactionSort::values(), self::pluck($props['columns'], 'key'));

        // Die Seite hängt in der Navigation.
        $shell = $props['shell'];
        $links = is_array($shell) ? ($shell['links'] ?? []) : [];

        $this->assertContains('Leistung', self::pluck($links, 'label'));
    }

    public function test_an_unknown_sort_key_is_rejected(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->get($this->url(['sort' => 'irgendwas']))
            ->assertSessionHasErrors('sort');
    }

    /**
     * Ein Zeitfenster mit lauter gleich langen Messungen.
     */
    private function aggregate(
        Project $project,
        string $name,
        int $durationUs,
        int $count = 1,
        int $failures = 0,
        string $op = 'http.server',
        string $environment = 'production',
        string $at = self::INSIDE,
    ): void {
        TransactionAggregate::factory()
            ->for($project)
            ->named($name, $op)
            ->inEnvironment($environment)
            ->measuring($durationUs, $count, $failures)
            ->at($at)
            ->create();
    }

    private function userRow(
        Project $project,
        string $name,
        string $identifier,
        string $at,
        bool $miserable = false,
    ): void {
        $factory = TransactionUserAggregate::factory()
            ->for($project)
            ->named($name)
            ->forUser($identifier)
            ->at($at);

        ($miserable ? $factory->miserable() : $factory)->create();
    }

    /**
     * Fenster mit lauter verschiedenen Transaktionsnamen — jede Zeile eine
     * eigene Gruppe, damit die Datenmenge tatsächlich in der Auswertung ankommt
     * und nicht schon in der Datenbank zusammenfällt.
     */
    private function fillWithAggregates(Project $project, int $count, int $offset): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->aggregate($project, sprintf('GET /seite-%03d', $offset + $index), 100_000 + $index);
        }
    }

    /**
     * Die Namen der Zeilen, alphabetisch — für Prüfungen, bei denen es auf den
     * Bestand ankommt und nicht auf die Reihenfolge.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<mixed>
     */
    private static function names(array $rows): array
    {
        $names = array_column($rows, 'name');
        sort($names);

        return $names;
    }

    /**
     * Eine Spalte aus einer Liste von Feld-Bäumen, ohne Annahmen über deren
     * Form — die Nutzlast einer Inertia-Seite ist zur Laufzeit nur `mixed`.
     *
     * @return list<mixed>
     */
    private static function pluck(mixed $rows, string $key): array
    {
        $values = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $values[] = is_array($row) ? ($row[$key] ?? null) : null;
        }

        return $values;
    }
}
