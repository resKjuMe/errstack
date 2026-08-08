<?php

namespace Tests\Feature\Performance;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionSpan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Trace-Ansicht: der Ablauf eines Aufrufs über alle Dienste hinweg.
 *
 * Geprüft wird vor allem das, was diese Ansicht von einer Liste unterscheidet:
 * dass die Verschachtelung stimmt, dass Teile aus verschiedenen Projekten
 * zusammenfinden, dass ein fehlender Zwischenschritt als Lücke dasteht statt
 * verschwiegen zu werden — und dass nichts sichtbar wird, was der Betrachter
 * nicht sehen darf.
 */
class TraceViewTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-08 12:00:00';

    /** Die Spur, um die es in fast jedem Fall hier geht. */
    private const TRACE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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

    public function test_it_shows_the_spans_of_a_trace_in_tree_order(): void
    {
        [$user, $project] = $this->context();

        $transaction = $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 1_000_000);

        $outer = $this->span($transaction, 'aaaaaaaaaaaaaaa2', $transaction->span_id, self::NOW, 800_000, 'app.handle');
        $this->span($transaction, 'aaaaaaaaaaaaaaa3', $outer->span_id, self::NOW, 400_000, 'db.sql.query');

        $response = $this->actingAs($user)->get($this->url());

        $this->assertSame('traces/Show', $this->page($response)['component'] ?? null);

        $rows = $this->rows($response);

        $this->assertSame(
            [
                ['GET /kasse', 0, 'transaction'],
                [null, 1, 'span'],
                [null, 2, 'span'],
            ],
            array_map(
                fn (array $row): array => [
                    $row['kind'] === 'transaction' ? $row['label'] : null,
                    $row['depth'],
                    $row['kind'],
                ],
                $rows,
            ),
        );

        $this->assertSame('db.sql.query', $rows[2]['op']);
    }

    public function test_it_joins_transactions_of_several_projects(): void
    {
        [$user, $frontend, $organization] = $this->context();
        $backend = Project::factory()->for($organization)->create(['name' => 'API', 'slug' => 'api']);

        $browser = $this->transaction($frontend, 'aaaaaaaaaaaaaaa1', null, self::NOW, 900_000);
        $call = $this->span($browser, 'aaaaaaaaaaaaaaa2', $browser->span_id, self::NOW, 700_000, 'http.client');

        // Die Transaktion des zweiten Dienstes hängt an dem Schritt, mit dem der
        // erste sie aufgerufen hat — das ist die Klammer über die Dienstgrenze.
        $this->transaction($backend, 'aaaaaaaaaaaaaaa3', $call->span_id, self::NOW, 600_000, 'GET /api/kasse');

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(['transaction', 'span', 'transaction'], array_column($rows, 'kind'));
        $this->assertSame('GET /kasse', $rows[0]['label']);
        $this->assertSame('GET /api/kasse', $rows[2]['label']);
        $this->assertSame([0, 1, 2], array_column($rows, 'depth'));
        $this->assertSame(['Webshop', 'Webshop', 'API'], array_column($rows, 'project'));

        $props = $this->waterfall($this->actingAs($user)->get($this->url()));

        $this->assertSame(['API', 'Webshop'], array_column($props['services'], 'name'));
        $this->assertSame(2, $props['transactions']);
    }

    public function test_a_missing_parent_span_becomes_a_visible_gap(): void
    {
        [$user, $project] = $this->context();

        // Der aufrufende Dienst ist nicht angebunden: die Transaktion nennt
        // einen übergeordneten Schritt, den es hier nicht gibt.
        $this->transaction($project, 'aaaaaaaaaaaaaaa1', 'ffffffffffffffff', self::NOW, 500_000);

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame(['missing', 'transaction'], array_column($rows, 'kind'));
        $this->assertSame([0, 1], array_column($rows, 'depth'));
        $this->assertSame('ffffffffffffffff', $rows[0]['spanId']);

        // Die Lücke hat keine eigene Messung und bekommt die Spanne ihrer
        // Kinder — sonst wäre der Balken darüber null Millisekunden breit.
        $this->assertSame(500_000, $rows[0]['durationUs']);
    }

    public function test_errors_are_marked_on_the_span_that_reported_them(): void
    {
        [$user, $project] = $this->context();

        $transaction = $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 500_000);
        $query = $this->span($transaction, 'aaaaaaaaaaaaaaa2', $transaction->span_id, self::NOW, 400_000, 'db.sql.query');

        $this->error($project, $query->span_id, 'SQLSTATE[HY000]: Verbindung weg');

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertSame([], $rows[0]['errors']);
        $this->assertCount(1, $rows[1]['errors']);
        $this->assertSame('SQLSTATE[HY000]: Verbindung weg', $rows[1]['errors'][0]['title']);
        $this->assertSame(1, $this->waterfall($this->actingAs($user)->get($this->url()))['errors']);
    }

    public function test_an_error_without_a_matching_span_is_listed_separately(): void
    {
        [$user, $project] = $this->context();

        $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 500_000);
        $this->error($project, 'ffffffffffffffff', 'Fehler im nicht angebundenen Dienst');

        $waterfall = $this->waterfall($this->actingAs($user)->get($this->url()));

        $this->assertCount(1, $waterfall['looseErrors']);
        $this->assertSame('Fehler im nicht angebundenen Dienst', $waterfall['looseErrors'][0]['title']);
    }

    public function test_it_hides_parts_that_belong_to_other_organizations(): void
    {
        [$user, $project] = $this->context();

        $mine = $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 500_000);

        $stranger = Project::factory()->for(Organization::factory())->create(['name' => 'Fremd']);
        $this->transaction($stranger, 'aaaaaaaaaaaaaaa9', $mine->span_id, self::NOW, 300_000, 'GET /fremd');
        $this->error($stranger, $mine->span_id, 'Fremder Fehler');

        $waterfall = $this->waterfall($this->actingAs($user)->get($this->url()));

        $this->assertSame(['GET /kasse'], array_column($waterfall['rows'], 'label'));
        $this->assertSame(0, $waterfall['errors']);
        $this->assertSame([], $waterfall['looseErrors']);
    }

    public function test_the_details_of_a_span_are_loaded_on_demand(): void
    {
        [$user, $project] = $this->context();

        $transaction = $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 500_000);
        $long = str_repeat('select * from users where id = ? ', 40);

        $span = $this->span($transaction, 'aaaaaaaaaaaaaaa2', $transaction->span_id, self::NOW, 400_000, 'db.sql.query');
        $span->forceFill(['description' => $long, 'data' => ['db.rows' => 12]])->save();

        // In der Liste steht der Text gekürzt: eine Spur mit zehntausend
        // Schritten wäre sonst ein Vielfaches der übrigen Seite.
        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertNotNull($rows[1]['label']);
        $this->assertLessThan(strlen($long), strlen((string) $rows[1]['label']));

        $props = $this->props($this->actingAs($user)->get($this->url(['schritt' => 'aaaaaaaaaaaaaaa2'])));

        $this->assertSame('aaaaaaaaaaaaaaa2', $props['selected']);
        $this->assertSame($long, $props['span']['description']);
        $this->assertSame([['name' => 'db.rows', 'value' => '12']], $props['span']['data']);
    }

    public function test_a_broken_link_shows_the_trace_instead_of_an_error(): void
    {
        [$user, $project] = $this->context();

        $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 500_000);

        // Ein abgeschnittener oder verstümmelter Verweis auf einen Schritt: die
        // Spur steht trotzdem da, nur ohne geöffneten Schritt.
        $props = $this->props($this->actingAs($user)->get($this->url(['schritt' => 'kein schritt'])));

        $this->assertNull($props['selected']);
        $this->assertNull($props['span']);
        $this->assertCount(1, $props['waterfall']['rows']);
    }

    public function test_a_trace_with_more_than_a_thousand_spans_stays_one_page_and_a_fixed_number_of_queries(): void
    {
        [$user, $project] = $this->context();

        $transaction = $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 5_000_000);

        // 1.200 Schritte, wie sie eine Seite mit einem N+1-Problem erzeugt.
        // Eingefügt in einem Rutsch: die Aufgabe ist die Ansicht, nicht das
        // Anlegen.
        $rows = [];

        for ($i = 0; $i < 1200; $i++) {
            $startedAt = CarbonImmutable::parse(self::NOW)->addMilliseconds($i);

            $rows[] = [
                'transaction_id' => $transaction->id,
                'project_id' => $project->id,
                'trace_id' => self::TRACE,
                'span_id' => sprintf('%016x', $i + 1),
                'parent_span_id' => $transaction->span_id,
                'op' => 'db.sql.query',
                'description' => 'select * from "products" where "id" = ?',
                'status' => 'ok',
                'started_at' => $startedAt->format('Y-m-d H:i:s.v'),
                'finished_at' => $startedAt->addMicroseconds(900)->format('Y-m-d H:i:s.v'),
                'duration_us' => 900,
                'position' => $i,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ];
        }

        DB::table('transaction_spans')->insert($rows);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $waterfall = $this->waterfall($this->actingAs($user)->get($this->url()));

        $this->assertCount(1201, $waterfall['rows']);
        $this->assertFalse($waterfall['truncated']);

        // Die Zusage ist eine feste Zahl an Abfragen, nicht eine je Ebene: der
        // Baum entsteht aus dem, was die drei Abfragen geliefert haben. Die
        // Grenze ist großzügig, weil Sitzung und Rechteprüfung mitzählen — sie
        // wächst nur nicht mit der Zahl der Schritte.
        $this->assertLessThan(20, $queries);
    }

    public function test_an_unknown_trace_shows_an_empty_view_instead_of_an_error(): void
    {
        [$user] = $this->context();

        $waterfall = $this->waterfall($this->actingAs($user)->get('/spur/'.str_repeat('b', 32)));

        $this->assertSame([], $waterfall['rows']);
        $this->assertSame(0, $waterfall['durationUs']);
    }

    public function test_an_error_links_to_its_trace(): void
    {
        [$user, $project] = $this->context();

        $this->transaction($project, 'aaaaaaaaaaaaaaa1', null, self::NOW, 500_000);
        $error = $this->error($project, 'aaaaaaaaaaaaaaa1', 'Kaputt');

        $this->actingAs($user)
            ->get('/spur/ereignis/'.$error->id)
            ->assertRedirect('/spur/'.self::TRACE.'?schritt=aaaaaaaaaaaaaaa1');
    }

    public function test_the_way_into_a_trace_is_closed_for_foreign_errors(): void
    {
        [$user] = $this->context();

        $stranger = Project::factory()->for(Organization::factory())->create();
        $error = $this->error($stranger, 'aaaaaaaaaaaaaaa1', 'Fremd');

        $this->actingAs($user)->get('/spur/ereignis/'.$error->id)->assertNotFound();
    }

    public function test_it_needs_an_account(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    /**
     * @return array{User, Project, Organization}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $project, $organization];
    }

    private function transaction(
        Project $project,
        string $spanId,
        ?string $parentSpanId,
        string $startedAt,
        int $durationUs,
        string $name = 'GET /kasse',
    ): Transaction {
        return Transaction::factory()->for($project)->create([
            'trace_id' => self::TRACE,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'name' => $name,
            'started_at' => $startedAt,
            'finished_at' => Carbon::parse($startedAt)->addMicroseconds($durationUs),
            'duration_us' => $durationUs,
        ]);
    }

    private function span(
        Transaction $transaction,
        string $spanId,
        string $parentSpanId,
        string $startedAt,
        int $durationUs,
        string $op,
    ): TransactionSpan {
        return TransactionSpan::factory()
            ->of($transaction, $parentSpanId)
            ->between($startedAt, $durationUs)
            ->create(['span_id' => $spanId, 'op' => $op]);
    }

    private function error(Project $project, ?string $spanId, string $title): Event
    {
        return Event::factory()->for($project)->create([
            'trace_id' => self::TRACE,
            'trace_span_id' => $spanId,
            'title' => $title,
            'contexts' => ['trace' => ['type' => 'trace', 'trace_id' => self::TRACE, 'span_id' => $spanId]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(array $query = []): string
    {
        return '/spur/'.self::TRACE.($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * Die Nutzlast der Inertia-Seite.
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
        /** @var array<string, mixed> $props */
        $props = is_array($this->page($response)['props'] ?? null) ? $this->page($response)['props'] : [];

        return $props;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function waterfall(TestResponse $response): array
    {
        $waterfall = $this->props($response)['waterfall'] ?? [];

        /** @var array<string, mixed> */
        return is_array($waterfall) ? $waterfall : [];
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        $rows = $this->waterfall($response)['rows'] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($rows) ? array_values($rows) : [];
    }
}
