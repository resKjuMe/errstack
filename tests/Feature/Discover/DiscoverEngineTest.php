<?php

namespace Tests\Feature\Discover;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\UserReport;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverEngine;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\DiscoverResult;
use App\Support\Discover\DiscoverRow;
use App\Support\Discover\TimeRange;
use App\Support\Performance\DurationHistogram;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Der Auswertungs-Motor: rechnet er, was er zu rechnen behauptet?
 *
 * Die Prüfungen hier sind bewusst gegen **von Hand nachgerechnete** Zahlen gestellt
 * und nicht gegen eine zweite Implementierung derselben Rechnung. Bei den
 * Perzentilen ist das nicht möglich — sie kommen aus der Klassenverteilung — und
 * dort steht die Erwartung deshalb als Rechnung mit denselben Klassen: das prüft,
 * dass die Klassengrenzen in SQL dieselben sind wie in PHP, was der einzige Ort ist,
 * an dem sich die beiden unbemerkt auseinanderentwickeln könnten.
 */
class DiscoverEngineTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private Project $project;

    private DiscoverEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein fester Zeitpunkt: eine Auswertung rechnet über einen Zeitraum, und ein
        // Test, der um Mitternacht anders ausgeht, ist keiner.
        $this->now = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->for($organization)->create();
        $this->engine = new DiscoverEngine;
    }

    private function range(int $hours = 24): TimeRange
    {
        return TimeRange::lastHours($hours, $this->now);
    }

    private function errors(): DiscoverQuery
    {
        return DiscoverQuery::for(Dataset::Errors, $this->project->id, $this->range());
    }

    private function transactions(): DiscoverQuery
    {
        return DiscoverQuery::for(Dataset::Transactions, $this->project->id, $this->range());
    }

    /**
     * Eine Meldung mit Browser-Kontext zu einem Zeitpunkt.
     */
    private function event(string $browser, string $version, ?CarbonImmutable $at = null, ?string $user = null): Event
    {
        return Event::factory()->for($this->project)->create([
            'occurred_at' => $at ?? $this->now->subHours(2),
            'received_at' => $at ?? $this->now->subHours(2),
            'contexts' => ['browser' => ['name' => $browser, 'version' => $version]],
            'user' => $user === null ? null : ['id' => $user],
        ]);
    }

    /**
     * Die eine Zeile eines Ergebnisses — mit der Prüfung, dass es sie gibt.
     */
    private function row(DiscoverResult $result): DiscoverRow
    {
        $row = $result->first();

        $this->assertNotNull($row, 'Die Auswertung hat keine Zeile geliefert.');

        return $row;
    }

    public function test_it_counts_events_grouped_by_browser(): void
    {
        $this->event('Chrome', '124.0');
        $this->event('Chrome', '124.0');
        $this->event('Firefox', '125.0');

        // Außerhalb des Zeitraums — die Grenze ist der eigentliche Gegenstand
        // dieser Prüfung: eine Auswertung, die zu weit greift, sieht richtig aus.
        $this->event('Chrome', '124.0', $this->now->subHours(30));

        $result = $this->engine->table($this->errors()->groupedBy(['browser'])->measuring(['count()']));

        $this->assertSame(['browser'], $result->groupBy);
        $this->assertSame(['count'], $result->aliases);
        $this->assertFalse($result->truncated);
        $this->assertSame(
            [['Chrome 124.0', 2.0], ['Firefox 125.0', 1.0]],
            array_map(
                static fn ($row): array => [$row->groups['browser'], $row->value('count')],
                $result->rows,
            ),
        );
    }

    public function test_a_missing_tag_is_its_own_group_and_not_invented(): void
    {
        $this->event('Chrome', '124.0');
        Event::factory()->for($this->project)->create([
            'occurred_at' => $this->now->subHour(),
            'received_at' => $this->now->subHour(),
            'contexts' => null,
        ]);

        $result = $this->engine->table($this->errors()->groupedBy(['browser'])->measuring(['count()']));

        $this->assertCount(2, $result->rows);
        $this->assertContains(null, array_map(static fn ($row): ?string => $row->groups['browser'], $result->rows));
    }

    public function test_it_counts_distinct_values(): void
    {
        $this->event('Chrome', '124.0', null, 'anna');
        $this->event('Chrome', '124.0', null, 'anna');
        $this->event('Chrome', '124.0', null, 'ben');

        $result = $this->engine->table($this->errors()->measuring(['count()', 'count_unique(user.id)']));

        $this->assertSame(3.0, $this->row($result)->value('count'));
        $this->assertSame(2.0, $this->row($result)->value('count_unique_user_id'));
    }

    public function test_it_groups_by_a_free_tag(): void
    {
        Event::factory()->for($this->project)->count(2)->create([
            'occurred_at' => $this->now->subHour(),
            'received_at' => $this->now->subHour(),
            'tags' => ['checkout_step' => 'payment'],
        ]);

        $result = $this->engine->table(
            $this->errors()->groupedBy(['tags[checkout_step]'])->measuring(['count()']),
        );

        $this->assertSame('payment', $this->row($result)->groups['tags[checkout_step]']);
        $this->assertSame(2.0, $this->row($result)->value('count'));
    }

    public function test_the_search_language_filters_without_change(): void
    {
        $this->event('Chrome', '124.0');
        $this->event('Firefox', '125.0');
        $this->event('Firefox', '125.0');

        $result = $this->engine->table(
            $this->errors()->withSearch('browser.name:Firefox')->measuring(['count()']),
        );

        $this->assertSame(2.0, $this->row($result)->value('count'));

        $negated = $this->engine->table(
            $this->errors()->withSearch('!browser.name:Firefox')->measuring(['count()']),
        );

        $this->assertSame(1.0, $this->row($negated)->value('count'));
    }

    public function test_an_unreadable_search_does_not_empty_the_result(): void
    {
        $this->event('Chrome', '124.0');

        $result = $this->engine->table($this->errors()->withSearch('(browser.name:Chrome')->measuring(['count()']));

        $this->assertNotNull($result->searchError);
        $this->assertSame(1.0, $this->row($result)->value('count'), 'Die Auswertung steht ungefiltert da.');
    }

    public function test_a_field_this_source_does_not_have_is_named(): void
    {
        $this->event('Chrome', '124.0');

        $result = $this->engine->table(
            DiscoverQuery::for(Dataset::UserReports, $this->project->id, $this->range())
                ->withSearch('bookmarks:me')
                ->measuring(['count()']),
        );

        $this->assertSame(['bookmarks'], $result->unavailable);
    }

    public function test_it_computes_average_minimum_and_maximum(): void
    {
        foreach ([100_000, 200_000, 600_000] as $durationUs) {
            Transaction::factory()->for($this->project)->lasting($durationUs)->create([
                'started_at' => $this->now->subHour(),
            ]);
        }

        $result = $this->engine->table(
            $this->transactions()->measuring(['count()', 'avg(duration)', 'min(duration)', 'max(duration)']),
        );

        $this->assertSame(3.0, $this->row($result)->value('count'));
        $this->assertSame(300_000.0, $this->row($result)->value('avg_duration'));
        $this->assertSame(100_000.0, $this->row($result)->value('min_duration'));
        $this->assertSame(600_000.0, $this->row($result)->value('max_duration'));
    }

    public function test_percentiles_use_the_same_classes_as_the_precomputed_ones(): void
    {
        $durations = [50, 100, 101, 200, 400, 1_000, 25_000, 300_000, 1_200_000, 9_000_000];

        foreach ($durations as $durationUs) {
            Transaction::factory()->for($this->project)->lasting($durationUs)->create([
                'started_at' => $this->now->subHour(),
            ]);
        }

        // Die Erwartung entsteht aus derselben Verteilung, in die die Aufnahme eine
        // Messung legt. Weicht die Klasseneinteilung in SQL davon ab, fällt es genau
        // hier auf — an den Werten, die auf einer Klassengrenze liegen (100, 200, 400).
        $histogram = DurationHistogram::empty();

        foreach ($durations as $durationUs) {
            $histogram->add($durationUs);
        }

        $result = $this->engine->table(
            $this->transactions()->measuring(['p50(duration)', 'p75(duration)', 'p95(duration)', 'p99(duration)']),
        );

        foreach (['p50' => 0.5, 'p75' => 0.75, 'p95' => 0.95, 'p99' => 0.99] as $alias => $percentile) {
            $this->assertSame(
                (float) $histogram->percentile($percentile),
                $this->row($result)->value($alias.'_duration'),
                'Das '.$alias.' weicht von der Verteilung ab.',
            );
        }
    }

    public function test_a_percentile_over_something_that_is_not_a_duration_is_refused(): void
    {
        $this->expectException(DiscoverException::class);

        $this->engine->table($this->transactions()->measuring(['p95(span_count)']));
    }

    public function test_it_computes_the_failure_rate(): void
    {
        Transaction::factory()->for($this->project)->count(3)->create(['started_at' => $this->now->subHour()]);
        Transaction::factory()->for($this->project)->failed()->create(['started_at' => $this->now->subHour()]);

        // `cancelled` zählt nicht als Fehlschlag: wer den Tab schließt, hat kein
        // Problem der überwachten Anwendung verursacht.
        Transaction::factory()->for($this->project)->failed('cancelled')->create(['started_at' => $this->now->subHour()]);

        $result = $this->engine->table($this->transactions()->measuring(['failure_rate()']));

        $this->assertSame(20.0, $this->row($result)->value('failure_rate'));
    }

    public function test_a_rate_without_rows_is_unknown_and_not_zero(): void
    {
        $result = $this->engine->table($this->transactions()->measuring(['count()', 'failure_rate()']));

        $this->assertSame(0.0, $this->row($result)->value('count'), 'Eine Anzahl ist auch bei null eine Aussage.');
        $this->assertNull($this->row($result)->value('failure_rate'), 'Aus nichts folgt keine Quote.');
    }

    public function test_it_computes_apdex_from_the_configured_thresholds(): void
    {
        config(['ingest.performance.apdex_threshold_us' => 300_000, 'ingest.performance.misery_factor' => 4]);

        // Zwei zufriedene, einer geduldig (zwischen 300 ms und 1,2 s), einer darüber:
        // (2 + 0,5) / 4.
        foreach ([100_000, 200_000, 800_000, 5_000_000] as $durationUs) {
            Transaction::factory()->for($this->project)->lasting($durationUs)->create([
                'started_at' => $this->now->subHour(),
            ]);
        }

        $result = $this->engine->table($this->transactions()->measuring(['apdex()']));

        $this->assertSame(0.625, $this->row($result)->value('apdex'));
    }

    public function test_apdex_is_refused_where_there_are_no_durations(): void
    {
        $this->expectException(DiscoverException::class);

        $this->engine->table($this->errors()->measuring(['apdex()']));
    }

    public function test_it_groups_user_reports(): void
    {
        UserReport::factory()->for($this->project)->count(2)->create([
            'received_at' => $this->now->subHour(),
            'url' => 'https://example.test/checkout',
        ]);
        UserReport::factory()->for($this->project)->create([
            'received_at' => $this->now->subHour(),
            'url' => 'https://example.test/cart',
        ]);

        $result = $this->engine->table(
            DiscoverQuery::for(Dataset::UserReports, $this->project->id, $this->range())
                ->groupedBy(['url'])
                ->measuring(['count()']),
        );

        $this->assertSame('https://example.test/checkout', $this->row($result)->groups['url']);
        $this->assertSame(2.0, $this->row($result)->value('count'));
    }

    public function test_a_count_over_the_precomputed_windows_sums_and_does_not_count_rows(): void
    {
        $this->windows([['count' => 10], ['count' => 5]]);

        $result = $this->engine->table(
            DiscoverQuery::for(Dataset::TransactionWindows, $this->project->id, $this->range())
                ->measuring(['count()']),
        );

        $this->assertSame(15.0, $this->row($result)->value('count'), 'Zwei Fenster sind nicht zwei Aufrufe.');
    }

    public function test_an_average_over_the_windows_weighs_by_measurements(): void
    {
        // Ein langsamer Aufruf in einer Minute und neunundneunzig schnelle in der
        // nächsten: der Mittelwert der Minuten wäre 550 ms, der der Aufrufe 14,5 ms.
        $this->windows([
            ['count' => 1, 'duration' => 1_000_000],
            ['count' => 99, 'duration' => 5_000],
        ]);

        $result = $this->engine->table(
            DiscoverQuery::for(Dataset::TransactionWindows, $this->project->id, $this->range())
                ->measuring(['avg(duration)']),
        );

        $this->assertSame(14_950.0, $this->row($result)->value('avg_duration'));
    }

    public function test_the_windows_cannot_count_distinct_values(): void
    {
        $this->expectException(DiscoverException::class);

        $this->engine->table(
            DiscoverQuery::for(Dataset::TransactionWindows, $this->project->id, $this->range())
                ->measuring(['count_unique(name)']),
        );
    }

    public function test_an_unknown_field_is_a_free_tag_where_the_source_has_tags(): void
    {
        // Kein Sonderfall, sondern die Regel: ein Feld, das nicht in der Liste steht,
        // ist ein Merkmal — das ist die Zusage, auf der die Suchsprache beruht.
        // Ergibt sich daraus nichts, ist die Gruppe leer und nicht ein Fehler.
        $this->event('Chrome', '124.0');

        $result = $this->engine->table($this->errors()->groupedBy(['nonsense'])->measuring(['count()']));

        $this->assertNull($this->row($result)->groups['nonsense']);
        $this->assertSame(1.0, $this->row($result)->value('count'));
    }

    public function test_an_unknown_group_field_is_refused_with_its_name(): void
    {
        // Die vorberechneten Fenster tragen keine Merkmale: dort ist ein unbekanntes
        // Feld tatsächlich unbekannt, und das gehört gesagt statt still ignoriert.
        try {
            $this->engine->table(
                DiscoverQuery::for(Dataset::TransactionWindows, $this->project->id, $this->range())
                    ->groupedBy(['nonsense'])
                    ->measuring(['count()']),
            );
            $this->fail('Ein unbekanntes Gruppierungsfeld wurde angenommen.');
        } catch (DiscoverException $error) {
            $this->assertSame('unknown_field', $error->reason);
            $this->assertSame('nonsense', $error->context['field']);
        }
    }

    /**
     * Vorberechnete Fenster, eines je Minute.
     *
     * @param  list<array{count: int, duration?: int, failures?: int}>  $windows
     */
    private function windows(array $windows): void
    {
        foreach ($windows as $index => $window) {
            $durationUs = $window['duration'] ?? 100_000;
            $count = $window['count'];

            TransactionAggregate::factory()->for($this->project)->create([
                'environment' => 'production',
                'name' => 'GET /checkout',
                'op' => 'http.server',
                'window_start' => $this->now->subHours(2)->addMinutes($index),
                'transaction_count' => $count,
                'extrapolated_count' => $count,
                'failure_count' => $window['failures'] ?? 0,
                'duration_sum_us' => $durationUs * $count,
                'duration_min_us' => $durationUs,
                'duration_max_us' => $durationUs,
                'duration_histogram' => [DurationHistogram::bucketFor($durationUs) => $count],
            ]);
        }
    }
}
