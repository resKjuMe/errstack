<?php

namespace Tests\Unit;

use App\Enums\VitalRating;
use App\Enums\WebVital;
use App\Support\Performance\Vitals\VitalHistogram;
use App\Support\Performance\Vitals\VitalReading;
use App\Support\Performance\Vitals\VitalSummary;
use PHPUnit\Framework\TestCase;

/**
 * Das Rechenwerk hinter dem Ladeerlebnis: Deutung der gemeldeten Messwerte,
 * Verteilung, Bewertung.
 *
 * Ohne Datenbank, weil hier nichts abgelegt wird. Geprüft wird der Teil, an dem
 * sich Fehler verstecken — Einheiten, Klassengrenzen und die Frage, welche der
 * beiden Zahlen (Bewertung oder Schätzwert) die andere korrigiert.
 */
class WebVitalMeasurementTest extends TestCase
{
    public function test_a_measurement_without_a_unit_is_read_as_milliseconds(): void
    {
        $reading = VitalReading::fromMeasurement('lcp', ['value' => 2400]);

        $this->assertNotNull($reading);
        $this->assertSame(WebVital::Lcp, $reading->vital);
        $this->assertSame(2_400_000, $reading->value);
    }

    public function test_every_time_unit_lands_in_the_same_number(): void
    {
        $units = [
            ['value' => 2_500_000_000, 'unit' => 'nanosecond'],
            ['value' => 2_500_000, 'unit' => 'microsecond'],
            ['value' => 2500, 'unit' => 'millisecond'],
            ['value' => 2.5, 'unit' => 'second'],
        ];

        foreach ($units as $entry) {
            $reading = VitalReading::fromMeasurement('lcp', $entry);

            $this->assertNotNull($reading, "Einheit „{$entry['unit']}\" wurde verworfen.");
            $this->assertSame(2_500_000, $reading->value, "Einheit „{$entry['unit']}\" wurde falsch gerechnet.");
        }
    }

    public function test_the_layout_shift_is_a_score_and_not_a_duration(): void
    {
        $reading = VitalReading::fromMeasurement('cls', ['value' => 0.25, 'unit' => '']);

        $this->assertNotNull($reading);
        $this->assertSame(250_000, $reading->value);
        // Genau auf der Grenze — die Spezifikation zählt das noch als „mäßig".
        $this->assertSame(VitalRating::NeedsImprovement, $reading->rating());
    }

    public function test_a_measurement_that_cannot_be_interpreted_is_dropped(): void
    {
        // Eine Einheit, die keine Zeit ist: eine Fehlmeldung des SDK. Sie als
        // Millisekunden zu lesen wäre eine erfundene Zahl.
        $this->assertNull(VitalReading::fromMeasurement('lcp', ['value' => 2500, 'unit' => 'byte']));

        // Eine Zeiteinheit an einer Punktzahl ist ebenso eine Fehlmeldung.
        $this->assertNull(VitalReading::fromMeasurement('cls', ['value' => 0.1, 'unit' => 'millisecond']));

        // Negative Werte gibt es nicht — sie kämen von falsch gestellten Uhren
        // und zögen jedes Perzentil nach unten.
        $this->assertNull(VitalReading::fromMeasurement('lcp', ['value' => -10]));

        // Was zu keinem bewerteten Messwert gehört, ist kein Web Vital.
        $this->assertNull(VitalReading::fromMeasurement('frames_slow', ['value' => 3]));
    }

    public function test_the_same_vital_is_taken_only_once(): void
    {
        $readings = VitalReading::all([
            'lcp' => ['value' => 2000],
            'measurements.lcp' => ['value' => 9000],
            'cls' => ['value' => 0.05, 'unit' => ''],
        ]);

        $this->assertSame(['lcp', 'cls'], array_keys($readings));
        $this->assertSame(2_000_000, $readings['lcp']->value);
    }

    public function test_the_thresholds_follow_the_specification(): void
    {
        // Die Grenzen werden asymmetrisch gelesen: „gut" schließt seine
        // Obergrenze ein, „schlecht" beginnt erst darüber.
        $this->assertSame(VitalRating::Good, WebVital::Lcp->rate(2_500_000));
        $this->assertSame(VitalRating::NeedsImprovement, WebVital::Lcp->rate(2_500_001));
        $this->assertSame(VitalRating::NeedsImprovement, WebVital::Lcp->rate(4_000_000));
        $this->assertSame(VitalRating::Poor, WebVital::Lcp->rate(4_000_001));
    }

    public function test_the_histogram_estimates_a_percentile_within_ten_percent(): void
    {
        // Die Zusage der feineren Klassen: ein Schätzwert, der nicht um das
        // Doppelte danebenliegt. Ohne sie wäre die Zahl neben der Bewertung
        // wertlos.
        foreach ([1_200_000, 2_400_000, 3_100_000, 7_800_000] as $value) {
            $histogram = VitalHistogram::empty();
            $histogram->add($value, 20);

            $estimate = $histogram->percentile(WebVital::PERCENTILE);

            $this->assertNotNull($estimate);
            $this->assertLessThan(
                0.1,
                abs($estimate - $value) / $value,
                "Der Schätzwert für {$value} liegt um mehr als zehn Prozent daneben.",
            );
        }
    }

    public function test_a_merged_histogram_is_the_sum_of_its_parts(): void
    {
        $first = VitalHistogram::empty();
        $first->add(1_000_000, 3);

        $second = VitalHistogram::empty();
        $second->add(1_000_000, 4);

        $first->merge($second);

        $this->assertSame(7, $first->count());
    }

    public function test_the_rating_comes_from_the_exact_counts_and_corrects_the_estimate(): void
    {
        // Drei von vier Messungen sind gut, die vierte ist schlecht: das p75
        // fällt damit noch auf die letzte gute Messung, und die Seite gilt als
        // gut. Aus der Verteilung allein wäre das an dieser Stelle nicht sicher
        // zu entscheiden.
        $histogram = VitalHistogram::empty();
        $histogram->add(2_400_000, 3);
        $histogram->add(9_000_000);

        $summary = VitalSummary::fromTotals(WebVital::Lcp, [
            'measurement_count' => 4,
            'good_count' => 3,
            'needs_improvement_count' => 0,
            'poor_count' => 1,
            'value_sum' => 3 * 2_400_000 + 9_000_000,
        ] + self::rowSums($histogram));

        $this->assertSame(VitalRating::Good, $summary->rating);

        // Und die angezeigte Zahl widerspricht der Bewertung nicht: sie wird in
        // die Grenzen der nachweislich richtigen Klasse gerückt.
        $this->assertNotNull($summary->value);
        $this->assertLessThanOrEqual(WebVital::Lcp->goodMax(), $summary->value);
    }

    public function test_a_vital_without_measurements_says_so(): void
    {
        $summary = VitalSummary::empty(WebVital::Inp);

        $this->assertSame(0, $summary->count);
        $this->assertNull($summary->rating);
        $this->assertNull($summary->value);
        // Kein Anteil ist etwas anderes als der Anteil null — der Balken bleibt
        // leer, statt „alles gut" zu behaupten.
        $this->assertSame(0.0, $summary->share(VitalRating::Good));
    }

    /**
     * Die Klassensummen, wie sie eine Abfrage liefern würde.
     *
     * @return array<string, int>
     */
    private static function rowSums(VitalHistogram $histogram): array
    {
        $sums = [];

        foreach ($histogram->toArray() as $bucket => $count) {
            $sums['vital_bucket_'.$bucket] = $count;
        }

        return $sums;
    }
}
