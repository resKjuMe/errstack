<?php

namespace Tests\Unit\Discover;

use App\Support\Discover\Aggregate;
use App\Support\Discover\Aggregation;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverLimits;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\Interval;
use App\Support\Discover\Ordering;
use App\Support\Discover\TimeRange;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Die Beschreibung einer Auswertung, ohne Datenbank: was sich an ihr erkennen
 * lässt, muss sich erkennen lassen, **bevor** gefragt wird.
 *
 * Der Fingerabdruck ist der wichtigste Teil davon. An ihm hängt der
 * Zwischenspeicher, und ein Abdruck, der ein Feld übersieht, liefert die Antwort
 * einer anderen Frage — der unangenehmste Fehler dieser Ecke, weil er wie ein
 * Rechenfehler aussieht.
 */
class DiscoverQueryTest extends TestCase
{
    private function range(): TimeRange
    {
        return TimeRange::of(
            CarbonImmutable::parse('2026-03-10 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-11 00:00:00', 'UTC'),
        );
    }

    private function question(): DiscoverQuery
    {
        return DiscoverQuery::for(Dataset::Errors, 1, $this->range())
            ->measuring(['count()'])
            ->groupedBy(['browser']);
    }

    public function test_an_aggregation_reads_its_written_form(): void
    {
        $aggregation = Aggregation::parse('p95(duration)');

        $this->assertSame(Aggregate::P95, $aggregation->aggregate);
        $this->assertSame('duration', $aggregation->field);
        $this->assertSame('p95_duration', $aggregation->alias());

        $this->assertSame('count', Aggregation::parse('count')->alias());
        $this->assertSame('count', Aggregation::parse('count()')->alias());
        $this->assertSame('count_unique_user_id', Aggregation::parse('count_unique(user.id)')->alias());
    }

    public function test_an_aggregation_without_its_field_is_rejected(): void
    {
        $this->expectException(DiscoverException::class);

        Aggregation::parse('avg()');
    }

    public function test_an_unknown_aggregate_is_rejected(): void
    {
        $this->expectException(DiscoverException::class);

        Aggregation::parse('median(duration)');
    }

    public function test_the_fingerprint_changes_with_every_part_of_the_question(): void
    {
        $base = $this->question();

        $variants = [
            'Suche' => $base->withSearch('browser:Chrome'),
            'Gruppierung' => $base->groupedBy(['os']),
            'Kennzahl' => $base->measuring(['count_unique(user.id)']),
            'Sortierung' => $base->orderedBy(Ordering::asc('count')),
            'Zeilenzahl' => $base->limitedTo(10),
            'Schrittweite' => $base->every('1h'),
            'Zeitzone' => $base->inTimezone('Europe/Berlin'),
            'Zeitraum' => $base->withRange(TimeRange::of(
                CarbonImmutable::parse('2026-03-09 00:00:00', 'UTC'),
                CarbonImmutable::parse('2026-03-11 00:00:00', 'UTC'),
            )),
        ];

        foreach ($variants as $what => $variant) {
            $this->assertNotSame(
                $base->fingerprint(),
                $variant->fingerprint(),
                'Der Fingerabdruck übersieht: '.$what,
            );
        }
    }

    public function test_the_same_question_has_the_same_fingerprint(): void
    {
        $this->assertSame($this->question()->fingerprint(), $this->question()->fingerprint());

        // Der Zwischenspeicher darf nicht daran hängen, ob jemand ihn benutzt: die
        // Antwort ist dieselbe.
        $this->assertSame($this->question()->fingerprint(), $this->question()->uncached()->fingerprint());
    }

    public function test_an_empty_search_is_the_same_as_none(): void
    {
        $this->assertSame(
            $this->question()->fingerprint(),
            $this->question()->withSearch('   ')->fingerprint(),
        );

        $this->assertNull($this->question()->withSearch('browser:Chrome')->withSearch(null)->search);
    }

    public function test_a_range_snaps_downwards(): void
    {
        $range = TimeRange::of(
            CarbonImmutable::parse('2026-03-10 09:00:31', 'UTC'),
            CarbonImmutable::parse('2026-03-10 10:00:59', 'UTC'),
        )->snapped(60);

        $this->assertSame('2026-03-10 09:00:00', $range->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-10 10:00:00', $range->to->format('Y-m-d H:i:s'));
    }

    public function test_a_range_ends_after_it_begins(): void
    {
        $this->expectException(DiscoverException::class);

        TimeRange::of(
            CarbonImmutable::parse('2026-03-10 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-10 09:00:00', 'UTC'),
        );
    }

    public function test_an_interval_counts_its_points_without_gaps(): void
    {
        $interval = Interval::parse('1h');

        $this->assertSame(24, $interval->points($this->range()));
        $this->assertCount(24, $interval->buckets($this->range()));
        $this->assertSame('2026-03-10 00:00:00', $interval->buckets($this->range())[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-10 23:00:00', $interval->buckets($this->range())[23]->format('Y-m-d H:i:s'));
    }

    public function test_an_unknown_interval_is_rejected(): void
    {
        $this->expectException(DiscoverException::class);

        Interval::parse('37s');
    }

    public function test_the_limits_say_what_they_allowed_and_what_was_asked(): void
    {
        $limits = DiscoverLimits::fromConfig();

        try {
            $limits->check($this->question()->limitedTo($limits->maxRows + 1));
            $this->fail('Die Zeilenzahl wurde nicht begrenzt.');
        } catch (DiscoverException $error) {
            $this->assertSame('limit', $error->reason);
            $this->assertSame('rows', $error->context['limit']);
            $this->assertSame($limits->maxRows, $error->context['allowed']);
            $this->assertSame($limits->maxRows + 1, $error->context['given']);
        }
    }

    public function test_a_query_without_a_measure_is_not_a_query(): void
    {
        $this->expectException(DiscoverException::class);

        DiscoverLimits::fromConfig()->check(
            DiscoverQuery::for(Dataset::Errors, 1, $this->range()),
        );
    }

    public function test_too_deep_a_grouping_is_rejected(): void
    {
        $limits = DiscoverLimits::fromConfig();
        $fields = array_fill(0, $limits->maxGroupFields + 1, 'browser');

        try {
            $limits->check($this->question()->groupedBy($fields));
            $this->fail('Die Tiefe der Gruppierung wurde nicht begrenzt.');
        } catch (DiscoverException $error) {
            $this->assertSame('group_fields', $error->context['limit']);
        }
    }

    public function test_too_long_a_range_is_rejected_before_the_database(): void
    {
        $limits = DiscoverLimits::fromConfig();
        $now = CarbonImmutable::parse('2026-03-10 00:00:00', 'UTC');

        try {
            $limits->check($this->question()->withRange(TimeRange::lastDays($limits->maxRangeDays + 1, $now)));
            $this->fail('Der Zeitraum wurde nicht begrenzt.');
        } catch (DiscoverException $error) {
            $this->assertSame('range_days', $error->context['limit']);
        }
    }

    public function test_too_many_points_are_rejected(): void
    {
        $limits = DiscoverLimits::fromConfig();
        $now = CarbonImmutable::parse('2026-03-10 00:00:00', 'UTC');

        try {
            $limits->check($this->question()->withRange(TimeRange::lastDays(30, $now))->every('1m'));
            $this->fail('Die Zahl der Stützstellen wurde nicht begrenzt.');
        } catch (DiscoverException $error) {
            $this->assertSame('points', $error->context['limit']);
        }
    }
}
