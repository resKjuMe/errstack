<?php

namespace Tests\Unit;

use App\Support\Performance\DurationHistogram;
use PHPUnit\Framework\TestCase;

/**
 * Die Verteilung, aus der die Perzentile kommen.
 *
 * Geprüft wird das, was die Vorberechnung überhaupt möglich macht: dass sich
 * zwei Verteilungen addieren lassen und dass ein Perzentil daraus in der
 * bekannten Richtung ungenau ist (nie zu niedrig). Beides ist Rechnung, keine
 * Datenbank — deshalb ohne Laravel.
 */
class DurationHistogramTest extends TestCase
{
    public function test_short_durations_share_the_first_bucket(): void
    {
        // Alles bis zur Untergrenze gehört zusammen: schneller als eine
        // Zehntelmillisekunde ist für eine Antwortzeit dasselbe wie „sofort".
        $this->assertSame(0, DurationHistogram::bucketFor(1));
        $this->assertSame(0, DurationHistogram::bucketFor(DurationHistogram::BASE_US));
    }

    public function test_each_bucket_covers_twice_the_span_of_the_one_before(): void
    {
        $this->assertSame(1, DurationHistogram::bucketFor(DurationHistogram::BASE_US + 1));
        $this->assertSame(2, DurationHistogram::bucketFor(DurationHistogram::BASE_US * 2 + 1));
        $this->assertSame(3, DurationHistogram::bucketFor(DurationHistogram::BASE_US * 4 + 1));
    }

    public function test_absurd_durations_land_in_the_last_bucket_instead_of_growing_the_table(): void
    {
        $this->assertSame(
            DurationHistogram::MAX_BUCKET,
            DurationHistogram::bucketFor(PHP_INT_MAX),
        );
    }

    public function test_an_empty_histogram_has_no_percentile(): void
    {
        $this->assertNull(DurationHistogram::empty()->percentile(0.95));
        $this->assertTrue(DurationHistogram::empty()->isEmpty());
    }

    public function test_the_percentile_never_understates_the_measured_duration(): void
    {
        $histogram = DurationHistogram::empty();

        // Neunundneunzig schnelle Aufrufe und ein langsamer: genau der Fall, für
        // den ein Mittelwert nichts taugt und das p95 alles sagt.
        for ($i = 0; $i < 99; $i++) {
            $histogram->add(10_000);
        }

        $histogram->add(4_000_000);

        $p50 = $histogram->percentile(0.5);
        $p100 = $histogram->percentile(1.0);

        $this->assertNotNull($p50);
        $this->assertNotNull($p100);

        // Ausgewiesen wird die Obergrenze der Klasse — nie weniger als der Wert
        // selbst, und höchstens dessen Doppeltes.
        $this->assertGreaterThanOrEqual(10_000, $p50);
        $this->assertLessThanOrEqual(20_000, $p50);
        $this->assertGreaterThanOrEqual(4_000_000, $p100);
        $this->assertLessThanOrEqual(8_000_000, $p100);
    }

    public function test_two_histograms_add_up(): void
    {
        // Der eigentliche Zweck: das p95 einer Stunde ist nicht aus sechzig
        // Minuten-p95 zu gewinnen, die Verteilung schon.
        $first = DurationHistogram::empty();
        $first->add(10_000, 30);

        $second = DurationHistogram::empty();
        $second->add(10_000, 10);
        $second->add(2_000_000, 2);

        $first->merge($second);

        $this->assertSame(42, $first->count());
        $this->assertSame(
            [DurationHistogram::bucketFor(10_000) => 40, DurationHistogram::bucketFor(2_000_000) => 2],
            $first->toArray(),
        );
    }

    public function test_a_stored_histogram_reads_back_unchanged(): void
    {
        $histogram = DurationHistogram::empty();
        $histogram->add(500);
        $histogram->add(750_000);

        $reloaded = DurationHistogram::fromStored($histogram->toArray());

        $this->assertSame($histogram->toArray(), $reloaded->toArray());
    }

    public function test_a_damaged_column_does_not_stop_the_ingest(): void
    {
        // Die Spalte ist ein Feld-Baum; was dort nicht hineinpasst, darf die
        // laufende Aufnahme nicht anhalten. Die Verteilung baut sich aus den
        // folgenden Messungen wieder auf.
        $histogram = DurationHistogram::fromStored([
            '2' => 5,
            'unsinn' => 3,
            '4' => 'auch unsinn',
            '999' => 7,
            '-1' => 1,
        ]);

        $this->assertSame([2 => 5], $histogram->toArray());
    }

    public function test_the_lower_bound_of_a_bucket_is_where_the_previous_one_ended(): void
    {
        $this->assertSame(0, DurationHistogram::lowerBound(0));
        $this->assertSame(DurationHistogram::BASE_US, DurationHistogram::lowerBound(1));
        $this->assertSame(DurationHistogram::BASE_US * 2, DurationHistogram::lowerBound(2));
    }
}
