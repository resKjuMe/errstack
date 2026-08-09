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
use App\Support\Discover\DiscoverSeries;
use App\Support\Discover\SeriesGroup;
use App\Support\Discover\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Zeitreihe: dieselbe Frage über die Zeit aufgeteilt.
 *
 * Die eigentliche Zusage steht in
 * {@see self::test_a_series_and_a_table_of_the_same_question_agree()} — und sie ist
 * der Grund, warum die Reihe die Gruppen der Tabelle übernimmt statt selbst zu
 * gruppieren. Zwei Ansichten derselben Frage, die sich widersprechen, sind schlimmer
 * als eine Ansicht.
 */
class DiscoverSeriesTest extends TestCase
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

    private function range(int $hours = 24): TimeRange
    {
        return TimeRange::lastHours($hours, $this->now);
    }

    private function errors(): DiscoverQuery
    {
        return DiscoverQuery::for(Dataset::Errors, $this->project->id, $this->range());
    }

    private function event(string $browser, CarbonImmutable $at): Event
    {
        return Event::factory()->for($this->project)->create([
            'occurred_at' => $at,
            'received_at' => $at,
            'contexts' => ['browser' => ['name' => $browser, 'version' => '1.0']],
        ]);
    }

    /**
     * Die eine Linie einer Reihe — mit der Prüfung, dass es sie gibt.
     */
    private function line(DiscoverSeries $series): SeriesGroup
    {
        $line = $series->first();

        $this->assertNotNull($line, 'Die Reihe hat keine Linie geliefert.');

        return $line;
    }

    public function test_a_series_has_a_point_for_every_step_even_where_nothing_happened(): void
    {
        $this->event('Chrome', $this->now->subHours(2));

        $series = $this->engine->series($this->errors()->measuring(['count()'])->every('1h'));

        $this->assertCount(1, $series->groups, 'Ohne Gruppierung gibt es genau eine Linie.');
        $this->assertCount(24, $this->line($series)->points);

        $values = array_map(static fn ($point): ?float => $point->values['count'], $this->line($series)->points);

        // Eine Lücke ist bei einer Anzahl eine Null und kein fehlender Punkt: sonst
        // zeichnet die Grafik eine Lücke als Sprung.
        $this->assertSame(1.0, max($values));
        $this->assertSame(23, count(array_filter($values, static fn (?float $value): bool => $value === 0.0)));
    }

    public function test_the_points_are_stamped_with_the_beginning_of_their_step(): void
    {
        $series = $this->engine->series($this->errors()->measuring(['count()'])->every('1h'));
        $points = $this->line($series)->points;

        $this->assertSame('2026-03-09 12:00:00', $points[0]->at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-10 11:00:00', $points[23]->at->format('Y-m-d H:i:s'));
    }

    public function test_a_measurement_lands_in_the_step_it_happened_in(): void
    {
        $this->event('Chrome', $this->now->subHours(3)->addMinutes(5));
        $this->event('Chrome', $this->now->subHours(3)->addMinutes(59));

        $series = $this->engine->series($this->errors()->measuring(['count()'])->every('1h'));
        $points = $this->line($series)->points;

        // 24 Stützstellen ab `now - 24h`; die drittletzte ist `now - 3h`.
        $this->assertSame(2.0, $points[21]->values['count']);
        $this->assertSame(0.0, $points[20]->values['count']);
        $this->assertSame(0.0, $points[22]->values['count']);
    }

    public function test_a_series_and_a_table_of_the_same_question_agree(): void
    {
        foreach ([1, 2, 2, 5, 5, 5] as $hoursAgo) {
            $this->event('Chrome', $this->now->subHours($hoursAgo));
        }

        foreach ([3, 7] as $hoursAgo) {
            $this->event('Firefox', $this->now->subHours($hoursAgo));
        }

        $query = $this->errors()->groupedBy(['browser'])->measuring(['count()']);

        $table = $this->engine->table($query);
        $series = $this->engine->series($query->every('1h'));

        $this->assertCount(2, $series->groups);

        foreach ($table->rows as $index => $row) {
            $line = $series->groups[$index];

            $this->assertSame($row->groups['browser'], $line->groups['browser'], 'Die Linien sind die Zeilen der Tabelle.');
            $this->assertSame($row->value('count'), $line->total('count'), 'Die Summe der Linie ist die Zahl der Zeile.');
        }
    }

    public function test_a_percentile_in_a_series_is_unknown_where_nothing_was_measured(): void
    {
        Transaction::factory()->for($this->project)->lasting(250_000)->create([
            'started_at' => $this->now->subHours(2),
        ]);

        $series = $this->engine->series(
            DiscoverQuery::for(Dataset::Transactions, $this->project->id, $this->range())
                ->measuring(['p95(duration)'])
                ->every('1h'),
        );

        $values = array_map(static fn ($point): ?float => $point->values['p95_duration'], $this->line($series)->points);

        // Aus null Messungen folgt keine Antwortzeit — und ein Alarm, der aus einer
        // Lücke „0 ms" liest, verstummt genau dann, wenn nichts mehr antwortet.
        $this->assertNotNull($values[22]);
        $this->assertNull($values[21]);
    }

    public function test_a_series_over_a_finer_step_splits_the_same_total(): void
    {
        foreach ([5, 20, 35, 50] as $minutesAgo) {
            $this->event('Chrome', $this->now->subMinutes($minutesAgo));
        }

        $query = DiscoverQuery::for(Dataset::Errors, $this->project->id, TimeRange::lastHours(1, $this->now))
            ->measuring(['count()']);

        $hourly = $this->engine->series($query->every('1h'));
        $quarterly = $this->engine->series($query->every('15m'));

        $this->assertCount(1, $this->line($hourly)->points);
        $this->assertCount(4, $this->line($quarterly)->points);
        $this->assertSame($this->line($hourly)->total('count'), $this->line($quarterly)->total('count'));
    }

    public function test_a_series_needs_a_step(): void
    {
        $this->expectException(DiscoverException::class);

        $this->engine->series($this->errors()->measuring(['count()']));
    }

    public function test_a_series_without_data_has_no_lines_when_grouped(): void
    {
        $series = $this->engine->series($this->errors()->groupedBy(['browser'])->measuring(['count()'])->every('1h'));

        $this->assertSame([], $series->groups);
    }
}
