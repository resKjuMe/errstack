<?php

namespace Tests\Feature\Performance;

use App\Models\Event;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\TransactionSpan;
use App\Models\User;
use App\Support\Performance\DurationHistogram;
use App\Support\Performance\TransactionDetail;
use App\Support\Performance\TransactionFacet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Detailanalyse einer Transaktion: die Seite, die sagt, **warum** etwas
 * langsam ist.
 *
 * Geprüft werden die beiden Arten von Zahlen getrennt, weil sie verschiedene
 * Zusagen tragen: die Kennzahlen kommen vollständig aus den Vorberechnungen,
 * die Aufschlüsselungen aus einer begrenzten Stichprobe der Einzelmessungen.
 * Dazu die Zusage, die man sonst erst im Betrieb bemerkt: die Zahl der Abfragen
 * wächst nicht mit der Datenmenge.
 */
class TransactionDetailTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-07 12:00:00';

    private const INSIDE = '2026-08-07 11:00:00';

    private const NAME = 'GET /checkout';

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
     * @param  array<string, mixed>  $query
     */
    private function url(array $query = []): string
    {
        return '/leistung/transaktion?'.http_build_query($query + [
            'name' => self::NAME,
            'op' => 'http.server',
            'tz' => 'UTC',
        ]);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function detail(TestResponse $response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');
        /** @var array<string, mixed> $page */
        $page = is_array($page) ? $page : [];

        /** @var array<string, mixed> $props */
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        /** @var array<string, mixed> $detail */
        $detail = is_array($props['detail'] ?? null) ? $props['detail'] : [];

        return $detail;
    }

    private function aggregate(Project $project, int $durationUs, int $count = 1, int $failures = 0, ?string $at = null): void
    {
        TransactionAggregate::factory()
            ->for($project)
            ->named(self::NAME)
            ->measuring($durationUs, $count, $failures)
            ->at($at ?? self::INSIDE)
            ->create();
    }

    /**
     * Eine Einzelmessung, wie die Stichprobe sie liest.
     */
    private function transaction(Project $project, int $durationUs, ?string $release = null, ?string $at = null): Transaction
    {
        $startedAt = CarbonImmutable::parse($at ?? self::INSIDE, 'UTC');

        return Transaction::factory()->for($project)->create([
            'name' => self::NAME,
            'op' => 'http.server',
            'release' => $release,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->addMicroseconds($durationUs),
            'duration_us' => $durationUs,
        ]);
    }

    private function span(Transaction $transaction, string $op, int $durationUs, ?string $description = null): void
    {
        TransactionSpan::query()->create([
            'transaction_id' => $transaction->id,
            'project_id' => $transaction->project_id,
            'trace_id' => $transaction->trace_id,
            'span_id' => substr(md5($op.$transaction->id.$durationUs), 0, 16),
            'parent_span_id' => $transaction->span_id,
            'op' => $op,
            'description' => $description,
            'status' => 'ok',
            'started_at' => $transaction->started_at,
            'finished_at' => $transaction->started_at->addMicroseconds($durationUs),
            'duration_us' => $durationUs,
            'position' => 0,
        ]);
    }

    /**
     * Das Perzentil, das für lauter gleich lange Messungen herauskommt.
     */
    private static function percentileOf(int $durationUs): int
    {
        return DurationHistogram::lowerBound(DurationHistogram::bucketFor($durationUs) + 1);
    }

    public function test_the_page_shows_the_metrics_of_the_transaction(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 1_500_000, 8, 2);

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        $this->assertSame(self::NAME, $detail['name']);
        $this->assertSame('http.server', $detail['op']);

        /** @var array<string, mixed> $summary */
        $summary = $detail['summary'];

        $this->assertSame(8, $summary['count']);
        $this->assertSame(0.25, $summary['failureRate']);
        $this->assertSame(self::percentileOf(1_500_000), $summary['p95Us']);
    }

    /**
     * Die Verteilung: die Frage, die ein Mittelwert verschluckt.
     */
    public function test_the_histogram_covers_the_measured_classes(): void
    {
        [$user, $project] = $this->context();

        // Zwei Gruppen: schnelle Aufrufe und eine Handvoll sehr langsamer.
        $this->aggregate($project, 20_000, 40);
        $this->aggregate($project, 4_000_000, 5);

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var list<array{fromUs: int, toUs: int|null, count: int}> $bars */
        $bars = $detail['histogram'];

        $this->assertNotSame([], $bars);
        $this->assertSame(45, array_sum(array_column($bars, 'count')));

        // Abgeschnitten wird an den belegten Klassen: die erste und die letzte
        // Klasse tragen etwas, sonst wäre die Grafik links und rechts leer.
        $this->assertGreaterThan(0, $bars[0]['count']);
        $this->assertGreaterThan(0, $bars[array_key_last($bars)]['count']);

        // Die Klassen schließen lückenlos aneinander an — sonst zeichnete die
        // Oberfläche eine Verteilung mit Löchern, die es nicht gibt.
        foreach ($bars as $index => $bar) {
            if ($index > 0) {
                $this->assertSame($bars[$index - 1]['toUs'], $bar['fromUs']);
            }
        }
    }

    /**
     * Der Verlauf: ein Punkt je Zeitfenster, nicht je Messung.
     */
    public function test_the_series_is_grouped_into_windows(): void
    {
        [$user, $project] = $this->context();

        // Drei Fenster derselben Stunde — sie gehören in einen Punkt.
        $this->aggregate($project, 500_000, 2, 0, '2026-08-07 11:00:00');
        $this->aggregate($project, 500_000, 3, 0, '2026-08-07 11:20:00');
        $this->aggregate($project, 500_000, 4, 0, '2026-08-07 11:40:00');

        // Eine andere Stunde — ein eigener Punkt.
        $this->aggregate($project, 500_000, 1, 0, '2026-08-07 09:15:00');

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var array{period: string, points: list<array{window: string, count: int, p95Us: int|null}>} $series */
        $series = $detail['series'];

        $this->assertSame('hour', $series['period']);
        $this->assertCount(2, $series['points']);
        $this->assertSame([1, 9], array_column($series['points'], 'count'));
        $this->assertSame(self::percentileOf(500_000), $series['points'][1]['p95Us']);
    }

    /**
     * Die Aufschlüsselung nach Vorgangsart — der eigentliche Zweck der Seite.
     */
    public function test_the_span_breakdown_names_the_biggest_time_consumer(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 1_000_000, 1);

        $transaction = $this->transaction($project, 1_000_000);

        $this->span($transaction, 'db.sql.query', 600_000, 'select * from orders');
        $this->span($transaction, 'db.sql.query', 200_000, 'select * from users');
        $this->span($transaction, 'http.client', 200_000, 'GET https://payment.example');

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var list<array<string, mixed>> $spans */
        $spans = $detail['spans'];

        $this->assertCount(2, $spans);
        $this->assertSame('db.sql.query', $spans[0]['op']);
        $this->assertSame(0.8, $spans[0]['share']);
        $this->assertSame(2, $spans[0]['count']);

        // Der Beleg ist der **langsamste** Schritt dieser Art: „und zwar diese
        // Abfrage" ist die Arbeitsanweisung, die aus dem Anteil folgt.
        $this->assertSame('select * from orders', $spans[0]['example']);

        $this->assertSame('http.client', $spans[1]['op']);
        $this->assertSame(0.2, $spans[1]['share']);
    }

    /**
     * Die Zusage aus den Akzeptanzkriterien: Beispielfälle werden über die
     * Perzentil-Bereiche gewählt und nicht zufällig.
     */
    public function test_the_examples_come_from_the_percentile_ranges(): void
    {
        [$user, $project] = $this->context();

        // Neunundvierzig schnelle Aufrufe und ein sehr langsamer. Ein zufällig
        // gezogener Fall wäre mit 98 % Wahrscheinlichkeit ein schneller — genau
        // der, dessentwegen niemand diese Seite öffnet.
        $this->aggregate($project, 30_000, 49);
        $this->aggregate($project, 9_000_000, 1);

        for ($i = 0; $i < 49; $i++) {
            $this->transaction($project, 30_000);
        }

        $slow = $this->transaction($project, 9_000_000);

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var list<array<string, mixed>> $samples */
        $samples = $detail['samples'];

        $percentiles = array_column($samples, 'percentile');

        $this->assertContains(0.5, $percentiles);
        $this->assertContains(0.95, $percentiles);

        $median = $samples[array_search(0.5, $percentiles, true)];
        $high = $samples[array_search(0.95, $percentiles, true)];

        $this->assertSame(30_000, $median['durationUs']);
        $this->assertSame(9_000_000, $high['durationUs']);
        $this->assertSame($slow->trace_id, $high['traceId']);

        // Solange es die Trace-Ansicht (PF4) nicht gibt, steht der Fall ohne
        // Link da — ein toter Link wäre die schlechtere Wahl.
        $this->assertNull($high['traceHref']);
    }

    /**
     * „Nur Version 2.0 ist langsam" — der Befund, wegen dessen es die
     * Aufschlüsselung nach Merkmalen gibt.
     */
    public function test_a_slow_release_is_marked_as_an_outlier(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 100_000, 20);

        for ($i = 0; $i < 10; $i++) {
            $this->transaction($project, 50_000, '1.0.0');
            $this->transaction($project, 50_000, '1.1.0');
            $this->transaction($project, 8_000_000, '2.0.0');
        }

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var list<array{key: string, values: list<array{value: string, outlier: bool}>}> $facets */
        $facets = $detail['facets'];

        $releases = null;

        foreach ($facets as $facet) {
            if ($facet['key'] === 'release') {
                $releases = $facet;
            }
        }

        $this->assertNotNull($releases);

        $outliers = array_column(
            array_values(array_filter($releases['values'], fn (array $value): bool => $value['outlier'])),
            'value',
        );

        $this->assertSame(['2.0.0'], $outliers);
    }

    /**
     * Ein Merkmal mit einem einzigen Wert wird gar nicht erst gezeigt: es gibt
     * nichts zu vergleichen.
     */
    public function test_a_single_valued_attribute_is_left_out(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 100_000, 5);

        for ($i = 0; $i < 5; $i++) {
            $this->transaction($project, 100_000, '1.0.0');
        }

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var list<array{key: string}> $facets */
        $facets = $detail['facets'];

        $this->assertNotContains('release', array_column($facets, 'key'));
        $this->assertNotContains('environment', array_column($facets, 'key'));
    }

    public function test_the_linked_issues_are_the_ones_reported_under_this_name(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 100_000, 3);

        $checkout = Issue::factory()->for($project)->create(['title' => 'TypeError: order is null']);
        $elsewhere = Issue::factory()->for($project)->create(['title' => 'RuntimeException: irgendwo anders']);

        Event::factory()->count(2)->for($project)->create([
            'issue_id' => $checkout->id,
            'transaction' => self::NAME,
            'occurred_at' => self::INSIDE,
        ]);

        Event::factory()->for($project)->create([
            'issue_id' => $elsewhere->id,
            'transaction' => 'GET /profil',
            'occurred_at' => self::INSIDE,
        ]);

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var list<array{id: int, title: string, count: int, href: string|null}> $issues */
        $issues = $detail['issues'];

        $this->assertCount(1, $issues);
        $this->assertSame($checkout->id, $issues[0]['id']);
        $this->assertSame(2, $issues[0]['count']);

        // Die Fehler-Detailseite (S2) gibt es noch nicht — dann steht der
        // Eintrag ohne Link da.
        $this->assertNull($issues[0]['href']);
    }

    /**
     * Eine Transaktion ohne Messungen im Zeitraum ist kein Fehler 404: der Name
     * ist eine Gruppierung und kein Datensatz.
     */
    public function test_a_range_without_measurements_shows_the_empty_state(): void
    {
        [$user] = $this->context();

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var array<string, mixed> $summary */
        $summary = $detail['summary'];

        $this->assertSame(0, $summary['count']);
        $this->assertSame([], $detail['histogram']);
        $this->assertSame([], $detail['spans']);
    }

    /**
     * Die Zusage, die man sonst erst im Betrieb bemerkt: die Zahl der Abfragen
     * hängt nicht an der Datenmenge.
     *
     * Verglichen werden zwei Läufe mit demselben Aufbau und sehr verschieden
     * vielen Messungen. Eine feste Zahl hinzuschreiben wäre der schwächere Test:
     * sie ändert sich mit jeder Anmeldung und jedem Shared-Prop und würde
     * nachgezogen, statt etwas zu bedeuten.
     */
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

        $this->fill($project, 120, '2026-08-07 10:00:00');

        $queries = [];
        $this->actingAs($user)->get($this->url())->assertOk();
        /** @var list<string> $large */
        $large = $queries;

        $this->assertSame(
            count($small),
            count($large),
            'Die Zahl der Abfragen hängt an der Datenmenge: '.implode(' | ', $large),
        );
    }

    /**
     * Messungen samt Vorberechnung und je einem Einzelschritt.
     */
    private function fill(Project $project, int $count, ?string $at = null): void
    {
        $this->aggregate($project, 100_000, $count, 0, $at);

        for ($i = 0; $i < $count; $i++) {
            $transaction = $this->transaction($project, 100_000, '1.0.0', $at);
            $this->span($transaction, 'db.sql.query', 50_000, 'select 1');
        }
    }

    /**
     * Die Stichprobe ist begrenzt — und die Seite sagt es, statt es zu
     * verschweigen.
     */
    public function test_the_sample_size_is_reported(): void
    {
        [$user, $project] = $this->context();

        $this->aggregate($project, 100_000, 3);

        for ($i = 0; $i < 3; $i++) {
            $this->transaction($project, 100_000);
        }

        $detail = $this->detail($this->actingAs($user)->get($this->url()));

        /** @var array{transactions: int, limit: int} $sample */
        $sample = $detail['sample'];

        $this->assertSame(3, $sample['transactions']);
        $this->assertSame(TransactionDetail::SAMPLE_LIMIT, $sample['limit']);
    }

    /**
     * Der Schwellwert der Auffälligkeit steht an einer Stelle und ist nicht in
     * der Auswertung verstreut.
     */
    public function test_the_outlier_threshold_is_a_named_constant(): void
    {
        $this->assertGreaterThan(1.0, TransactionFacet::OUTLIER_FACTOR);
        $this->assertGreaterThan(1, TransactionFacet::MIN_MEASUREMENTS);
    }
}
