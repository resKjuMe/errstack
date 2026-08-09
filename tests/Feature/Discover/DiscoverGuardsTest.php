<?php

namespace Tests\Feature\Discover;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Transaction;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverEngine;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\Ordering;
use App\Support\Discover\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Was der Motor zusagt, wenn eine Frage zu groß wird: er sagt es.
 *
 * Der Gegenstand dieser Prüfungen ist nicht, **dass** gekürzt wird — das ist
 * unvermeidlich —, sondern dass eine gekürzte Antwort als solche erkennbar ist. Eine
 * abgeschnittene Rangliste, die aussieht wie eine vollständige, ist die unangenehmste
 * Sorte falscher Antwort: sie führt zu richtigen Schlüssen aus falschen Zahlen.
 */
class DiscoverGuardsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private Project $project;

    private DiscoverEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);

        $organization = Organization::factory()->create();
        $this->project = Project::factory()->for($organization)->create();
        $this->engine = new DiscoverEngine;
    }

    private function errors(): DiscoverQuery
    {
        return DiscoverQuery::for(Dataset::Errors, $this->project->id, TimeRange::lastHours(24, $this->now));
    }

    private function event(string $browser): Event
    {
        return Event::factory()->for($this->project)->create([
            'occurred_at' => $this->now->subHour(),
            'received_at' => $this->now->subHour(),
            'contexts' => ['browser' => ['name' => $browser, 'version' => '1.0']],
        ]);
    }

    public function test_more_groups_than_asked_for_are_reported_and_not_hidden(): void
    {
        foreach (['Chrome', 'Firefox', 'Safari'] as $browser) {
            $this->event($browser);
        }

        $result = $this->engine->table($this->errors()->groupedBy(['browser'])->measuring(['count()'])->limitedTo(2));

        $this->assertCount(2, $result->rows);
        $this->assertTrue($result->truncated);
    }

    public function test_a_complete_answer_is_not_marked_as_cut(): void
    {
        foreach (['Chrome', 'Firefox'] as $browser) {
            $this->event($browser);
        }

        $result = $this->engine->table($this->errors()->groupedBy(['browser'])->measuring(['count()'])->limitedTo(2));

        $this->assertCount(2, $result->rows);
        $this->assertFalse($result->truncated);
    }

    public function test_a_ranking_by_percentile_is_sorted_even_though_sql_cannot(): void
    {
        foreach ([['GET /slow', 2_000_000], ['GET /fast', 10_000], ['GET /middle', 250_000]] as [$name, $durationUs]) {
            Transaction::factory()->for($this->project)->lasting($durationUs)->create([
                'name' => $name,
                'started_at' => $this->now->subHour(),
            ]);
        }

        $result = $this->engine->table(
            DiscoverQuery::for(Dataset::Transactions, $this->project->id, TimeRange::lastHours(24, $this->now))
                ->groupedBy(['name'])
                ->measuring(['p95(duration)'])
                ->orderedBy(Ordering::desc('p95_duration')),
        );

        $this->assertSame(
            ['GET /slow', 'GET /middle', 'GET /fast'],
            array_map(static fn ($row): ?string => $row->groups['name'], $result->rows),
        );
    }

    public function test_a_ranking_by_a_group_field_is_possible(): void
    {
        foreach (['Safari', 'Chrome'] as $browser) {
            $this->event($browser);
        }

        $result = $this->engine->table(
            $this->errors()->groupedBy(['browser'])->measuring(['count()'])->orderedBy('browser'),
        );

        $this->assertSame(
            ['Chrome 1.0', 'Safari 1.0'],
            array_map(static fn ($row): ?string => $row->groups['browser'], $result->rows),
        );
    }

    public function test_sorting_by_something_that_is_neither_measure_nor_grouping_is_refused(): void
    {
        try {
            $this->engine->table($this->errors()->groupedBy(['browser'])->measuring(['count()'])->orderedBy('os'));
            $this->fail('Eine unmögliche Sortierung wurde angenommen.');
        } catch (DiscoverException $error) {
            $this->assertSame('invalid', $error->reason);
        }
    }

    public function test_the_same_question_is_answered_from_the_cache(): void
    {
        $this->event('Chrome');

        $query = $this->errors()->measuring(['count()']);

        $first = $this->engine->table($query);
        $this->assertFalse($first->cached);

        // Nach dem ersten Lesen kommt die Zahl aus dem Zwischenspeicher — auch wenn
        // sich die Daten inzwischen geändert haben. Genau das ist die Zusage: gleiche
        // Frage, gleiche Antwort, für die Dauer des Rasters.
        $this->event('Chrome');

        $second = $this->engine->table($query);

        $this->assertTrue($second->cached);
        $this->assertSame(1.0, $second->first()?->value('count'));
    }

    public function test_a_reader_can_refuse_the_cache(): void
    {
        $this->event('Chrome');

        $query = $this->errors()->measuring(['count()'])->uncached();

        $this->assertFalse($this->engine->table($query)->cached);

        $this->event('Chrome');

        $second = $this->engine->table($query);

        $this->assertFalse($second->cached);
        $this->assertSame(2.0, $second->first()?->value('count'));
    }

    public function test_a_table_and_a_series_do_not_share_their_answer(): void
    {
        $this->event('Chrome');

        $query = $this->errors()->measuring(['count()'])->every('1h');

        $table = $this->engine->table($query);
        $series = $this->engine->series($query);

        $this->assertSame(1.0, $table->first()?->value('count'));
        $this->assertSame(1.0, $series->first()?->total('count'));
        $this->assertFalse($series->cached);
    }

    public function test_a_range_beyond_the_limit_never_reaches_the_database(): void
    {
        try {
            $this->engine->table(
                DiscoverQuery::for(Dataset::Errors, $this->project->id, TimeRange::lastDays(365, $this->now))
                    ->measuring(['count()']),
            );
            $this->fail('Der Zeitraum wurde nicht begrenzt.');
        } catch (DiscoverException $error) {
            $this->assertSame('limit', $error->reason);
            $this->assertSame('range_days', $error->context['limit']);
        }
    }

    public function test_a_series_shows_at_most_as_many_lines_as_allowed(): void
    {
        config(['discover.max_series_groups' => 2]);

        foreach (['Chrome', 'Firefox', 'Safari'] as $browser) {
            $this->event($browser);
        }

        $engine = new DiscoverEngine;

        $series = $engine->series($this->errors()->groupedBy(['browser'])->measuring(['count()'])->every('1h'));

        $this->assertCount(2, $series->groups);
        $this->assertTrue($series->truncated);
    }
}
