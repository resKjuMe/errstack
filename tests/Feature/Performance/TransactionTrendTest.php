<?php

namespace Tests\Feature\Performance;

use App\Enums\DeliveryStatus;
use App\Enums\TrendDirection;
use App\Models\Deploy;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\TransactionAggregate;
use App\Models\TransactionTrendDetection;
use App\Models\User;
use App\Support\Performance\Trends\TrendScan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\BreakpointScanTest;

/**
 * Die Trend-Erkennung von der Vorberechnung bis zur Liste.
 *
 * Geprüft wird die Kette, nicht die Statistik — die hat ihren eigenen Test ohne
 * Datenbank ({@see BreakpointScanTest}). Hier geht es um das, was
 * erst im Zusammenspiel schiefgehen kann: wird der Bruch aus den vorberechneten
 * Fenstern überhaupt gefunden, wird er der richtigen Auslieferung zugeordnet,
 * geht **einmal** eine Meldung hinaus und nicht bei jedem Durchlauf, und steht
 * am Ende in der Liste, was dort stehen soll.
 */
class TransactionTrendTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein fester Zeitpunkt: die Suche rechnet über ein gleitendes Fenster, und
     * ein Test, der um Mitternacht anders ausgeht, ist keiner. Volle Stunde,
     * damit das Raster der Fenster ohne Rest aufgeht.
     */
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-07 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
        CarbonImmutable::setTestNow($this->now);

        // Die Zustellung selbst gehört zu A1; hier zählt, **dass** etwas
        // eingereiht wird.
        Queue::fake();
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

        NotificationChannel::factory()->for($organization)->create();

        $user->switchOrganization($organization);

        return [$user, $project];
    }

    /**
     * Legt einen Verlauf an: `$hours` Stunden vor dem Bruch auf der einen Höhe,
     * danach auf der anderen.
     *
     * Je Stunde ein Fenster mit lauter gleich langen Messungen — dann ist das
     * p95 die Obergrenze seiner Klasse und damit vorhersagbar, ohne die Rechnung
     * der Verteilung nachzubauen.
     */
    private function history(
        Project $project,
        int $hours,
        int $beforeUs,
        int $afterUs,
        string $name = 'GET /kasse',
        int $perWindow = 20,
    ): CarbonImmutable {
        $end = $this->now->startOfHour();
        $breakpoint = $end->subHours($hours);
        $start = $breakpoint->subHours($hours);

        for ($hour = 0; $hour < 2 * $hours; $hour++) {
            $at = $start->addHours($hour);

            TransactionAggregate::factory()
                ->for($project)
                ->named($name)
                ->measuring($hour < $hours ? $beforeUs : $afterUs, $perWindow)
                ->at($at)
                ->create();
        }

        return $breakpoint;
    }

    private function scan(): TrendScan
    {
        return app(TrendScan::class);
    }

    private function deliveries(): int
    {
        return NotificationDelivery::query()->where('status', DeliveryStatus::Pending)->count();
    }

    /**
     * Die Adresse der Liste. Die Zeitzone steht immer dabei, damit der
     * aufgelöste Zeitraum nicht von der Einstellung der Anwendung abhängt.
     *
     * Der Zeitraum ist ausdrücklich weit gewählt: die Standardvorgabe umfasst
     * 24 Stunden, und ein Bruch, der zwölf Stunden zurückliegt, wäre darin zwar
     * enthalten — bei einem längeren Verlauf aber nicht mehr.
     *
     * @param  array<string, mixed>  $query
     */
    private function url(array $query = []): string
    {
        return route('performance.trends.index', $query + ['tz' => 'UTC', 'period' => '7d']);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');
        /** @var array<string, mixed> $page */
        $page = is_array($page) ? $page : [];

        /** @var array<string, mixed> $props */
        $props = $page['props'] ?? [];

        /** @var array<string, mixed> $trends */
        $trends = $props['trends'] ?? [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $trends['data'] ?? [];

        return $rows;
    }

    public function test_a_transaction_that_turned_slower_is_found_and_reported(): void
    {
        [, $project] = $this->context();

        // Der Fall aus dem Auftrag: eine Seite rutscht von 200 ms auf 900 ms.
        $breakpoint = $this->history($project, 12, 200_000, 900_000);

        $result = $this->scan()->run($this->now);

        $this->assertSame(1, $result['found']);
        $this->assertSame(1, $result['notified']);

        $detection = TransactionTrendDetection::query()->firstOrFail();

        $this->assertSame('GET /kasse', $detection->name);
        $this->assertSame(TrendDirection::Worse, $detection->direction);
        $this->assertSame('production', $detection->environment);
        $this->assertSame(
            $breakpoint->format('Y-m-d H:i:s'),
            $detection->breakpoint_at->format('Y-m-d H:i:s'),
        );
        $this->assertGreaterThan($detection->before_p95_us, $detection->after_p95_us);
        $this->assertSame(240, $detection->before_count);
        $this->assertSame(240, $detection->after_count);
        $this->assertNotNull($detection->notified_at);
        $this->assertNull($detection->seen_at);

        $this->assertSame(1, $this->deliveries());
    }

    public function test_the_same_break_is_not_reported_twice(): void
    {
        [, $project] = $this->context();

        $this->history($project, 12, 200_000, 900_000);

        $this->scan()->run($this->now);
        $second = $this->scan()->run($this->now);

        // Der Bruch wird erneut gefunden — das ist richtig, er steht ja noch in
        // den Daten. Gemeldet wird er nicht noch einmal, und eine zweite Zeile
        // gibt es auch nicht.
        $this->assertSame(1, $second['found']);
        $this->assertSame(0, $second['notified']);
        $this->assertSame(1, TransactionTrendDetection::query()->count());
        $this->assertSame(1, $this->deliveries());
    }

    public function test_an_improvement_is_recorded_but_nobody_is_woken_up(): void
    {
        [, $project] = $this->context();

        $this->history($project, 12, 900_000, 200_000);

        $result = $this->scan()->run($this->now);

        $this->assertSame(1, $result['found']);
        $this->assertSame(0, $result['notified']);

        $detection = TransactionTrendDetection::query()->firstOrFail();

        $this->assertSame(TrendDirection::Better, $detection->direction);
        $this->assertNull($detection->notified_at);
        $this->assertSame(0, $this->deliveries());
    }

    public function test_a_steady_transaction_produces_nothing(): void
    {
        [, $project] = $this->context();

        $this->history($project, 12, 200_000, 200_000);

        $result = $this->scan()->run($this->now);

        $this->assertSame(0, $result['found']);
        $this->assertSame(0, TransactionTrendDetection::query()->count());
        $this->assertSame(0, $this->deliveries());
    }

    public function test_the_break_is_linked_to_the_deploy_that_fits(): void
    {
        [, $project] = $this->context();

        $breakpoint = $this->history($project, 12, 200_000, 900_000);

        // Eine Auslieferung kurz vor dem Umschlag — und eine zweite, die zwei
        // Tage her ist. Nur die erste ist eine Erklärung.
        $release = Release::factory()->for($project)->create(['version' => 'shop@2.4.0']);
        $deploy = Deploy::factory()->of($release, 'production')->create([
            'finished_at' => $breakpoint->subMinutes(10),
        ]);

        $old = Release::factory()->for($project)->create(['version' => 'shop@2.3.0']);
        Deploy::factory()->of($old, 'production')->create([
            'finished_at' => $breakpoint->subDays(2),
        ]);

        $this->scan()->run($this->now);

        $this->assertSame($deploy->id, TransactionTrendDetection::query()->firstOrFail()->deploy_id);
    }

    public function test_a_deploy_to_another_environment_explains_nothing(): void
    {
        [, $project] = $this->context();

        $breakpoint = $this->history($project, 12, 200_000, 900_000);

        $release = Release::factory()->for($project)->create(['version' => 'shop@2.4.0']);
        Deploy::factory()->of($release, 'staging')->create([
            'finished_at' => $breakpoint->subMinutes(10),
        ]);

        $this->scan()->run($this->now);

        $this->assertNull(TransactionTrendDetection::query()->firstOrFail()->deploy_id);
    }

    public function test_the_list_shows_regressions_and_improvements(): void
    {
        [$user, $project] = $this->context();

        TransactionTrendDetection::factory()->for($project)->forTransaction('GET /kasse')->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);
        TransactionTrendDetection::factory()->for($project)->forTransaction('GET /suche')->improvement()->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);

        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertCount(2, $rows);

        // Die Verschlechterung steht oben: eine Verbesserung ist eine
        // Bestätigung, eine Verschlechterung ist Arbeit.
        $this->assertSame('GET /kasse', $rows[0]['name']);
        $this->assertSame('worse', $rows[0]['direction']);
        $this->assertSame('GET /suche', $rows[1]['name']);
        $this->assertSame('better', $rows[1]['direction']);
    }

    public function test_the_list_can_be_narrowed_to_regressions(): void
    {
        [$user, $project] = $this->context();

        TransactionTrendDetection::factory()->for($project)->forTransaction('GET /kasse')->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);
        TransactionTrendDetection::factory()->for($project)->forTransaction('GET /suche')->improvement()->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);

        $rows = $this->rows($this->actingAs($user)->get($this->url(['direction' => 'worse'])));

        $this->assertCount(1, $rows);
        $this->assertSame('GET /kasse', $rows[0]['name']);
    }

    public function test_a_break_outside_the_period_is_not_shown(): void
    {
        [$user, $project] = $this->context();

        // Vor drei Wochen umgeschlagen — die Feststellung steht in der Tabelle,
        // aber nicht in dieser Woche.
        TransactionTrendDetection::factory()->for($project)->create([
            'breakpoint_at' => $this->now->subWeeks(3),
        ]);

        $this->assertCount(0, $this->rows($this->actingAs($user)->get($this->url())));
    }

    public function test_a_regression_can_be_marked_as_seen_and_back(): void
    {
        [$user, $project] = $this->context();

        $detection = TransactionTrendDetection::factory()->for($project)->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('performance.trends.seen', $detection))
            ->assertRedirect();

        $detection->refresh();

        $this->assertNotNull($detection->seen_at);
        $this->assertSame($user->id, $detection->seen_by_id);

        $this->actingAs($user)
            ->delete(route('performance.trends.unseen', $detection))
            ->assertRedirect();

        $this->assertNull($detection->refresh()->seen_at);
    }

    public function test_the_list_shows_the_open_ones_first_hand(): void
    {
        [$user, $project] = $this->context();

        TransactionTrendDetection::factory()->for($project)->forTransaction('GET /kasse')->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);
        TransactionTrendDetection::factory()->for($project)->forTransaction('GET /suche')->seen()->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);

        // Ohne Angabe zeigt die Liste, was noch offen ist — sie ist eine
        // Arbeitsliste, und was jemand abgehakt hat, gehört nicht mehr obenauf.
        $rows = $this->rows($this->actingAs($user)->get($this->url()));

        $this->assertCount(1, $rows);
        $this->assertSame('GET /kasse', $rows[0]['name']);

        // Auf Wunsch aber schon.
        $this->assertCount(2, $this->rows($this->actingAs($user)->get($this->url(['seen' => 'alle']))));
    }

    public function test_a_stranger_cannot_mark_a_break_as_seen(): void
    {
        [, $project] = $this->context();

        $detection = TransactionTrendDetection::factory()->for($project)->create([
            'breakpoint_at' => $this->now->subDay(),
        ]);

        $stranger = User::factory()->create();
        $other = Organization::factory()->withMember($stranger)->create();
        $stranger->switchOrganization($other);

        $this->actingAs($stranger)
            ->post(route('performance.trends.seen', $detection))
            ->assertForbidden();

        $this->assertNull($detection->refresh()->seen_at);
    }
}
