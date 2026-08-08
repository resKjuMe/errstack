<?php

namespace Tests\Unit;

use App\Enums\TrendDirection;
use App\Support\Performance\DurationHistogram;
use App\Support\Performance\Trends\BreakpointScan;
use App\Support\Performance\Trends\TrendWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Die Rechnung hinter der Trend-Erkennung.
 *
 * Ohne Laravel und ohne Datenbank: geprüft wird der statistische Kern — findet
 * er den Umschlag, und schweigt er, wenn die Daten ihn nicht hergeben. Der Weg
 * dorthin (Abfragen, Fortschreibung, Meldung) hat seinen eigenen Test.
 *
 * Die Zusage aus dem Auftrag steht in den beiden Gruppen: eine Verschlechterung
 * wird mit Zeitpunkt und Vorher/Nachher gefunden — und bei zu wenig Substanz
 * (ein einzelner Ausreißer, zu wenige Messungen, zu kurze Strecke) kommt nichts
 * heraus.
 */
class BreakpointScanTest extends TestCase
{
    /**
     * Ein Verlauf aus gleich langen Stundenfenstern.
     *
     * Alle Messungen eines Fensters in derselben Dauer: dann ist jedes Perzentil
     * die Obergrenze seiner Klasse und damit vorhersagbar, ohne die Rechnung der
     * Verteilung nachzubauen.
     *
     * @param  list<int>  $durationsUs  je Stunde eine Dauer
     * @return list<TrendWindow>
     */
    private function windows(array $durationsUs, int $perWindow = 20): array
    {
        $start = CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC');

        return array_values(array_map(
            static fn (int $durationUs, int $index): TrendWindow => new TrendWindow(
                at: $start->addHours($index),
                count: $perWindow,
                histogram: DurationHistogram::fromStored([
                    DurationHistogram::bucketFor($durationUs) => $perWindow,
                ]),
            ),
            $durationsUs,
            array_keys($durationsUs),
        ));
    }

    /**
     * @return list<int>
     */
    private function level(int $hours, int $durationUs): array
    {
        return array_fill(0, $hours, $durationUs);
    }

    public function test_it_finds_the_hour_at_which_a_transaction_turned_slower(): void
    {
        // Der Fall aus dem Auftrag: eine Seite rutscht von 200 ms auf 900 ms.
        $windows = $this->windows([
            ...$this->level(12, 200_000),
            ...$this->level(12, 900_000),
        ]);

        $breakpoint = BreakpointScan::find($windows);

        $this->assertNotNull($breakpoint);
        $this->assertSame(TrendDirection::Worse, $breakpoint->direction);
        $this->assertTrue($breakpoint->isRegression());

        // Der Bruchpunkt ist der Anfang des ersten Fensters **nach** dem
        // Umschlag — die zwölfte Stunde nach dem Anfang.
        $this->assertSame('2026-08-01 12:00:00', $breakpoint->at->format('Y-m-d H:i:s'));

        // Die Höhen kommen aus den zusammengelegten Verteilungen: die Klasse, in
        // die 200 ms fallen, endet bei 204,8 ms, die von 900 ms bei 1,6384 s.
        $this->assertGreaterThan($breakpoint->beforeP95Us, $breakpoint->afterP95Us);
        $this->assertGreaterThan(2.0, $breakpoint->changeRatio);

        $this->assertSame(240, $breakpoint->beforeCount);
        $this->assertSame(240, $breakpoint->afterCount);
        $this->assertGreaterThanOrEqual(BreakpointScan::MINIMUM_Z, $breakpoint->zScore);
    }

    public function test_it_finds_an_improvement_as_well(): void
    {
        // Dieselbe Rechnung mit umgekehrtem Vorzeichen: „ist die Optimierung
        // angekommen" ist dieselbe Frage wie „ist etwas langsamer geworden".
        $windows = $this->windows([
            ...$this->level(12, 900_000),
            ...$this->level(12, 200_000),
        ]);

        $breakpoint = BreakpointScan::find($windows);

        $this->assertNotNull($breakpoint);
        $this->assertSame(TrendDirection::Better, $breakpoint->direction);
        $this->assertFalse($breakpoint->isRegression());
        $this->assertLessThan(0.0, $breakpoint->changeRatio);
    }

    public function test_a_single_outlier_hour_is_not_a_trend(): void
    {
        // Genau der Fall, für den der Rangsummentest gewählt wurde: eine Stunde,
        // in der die Datenbank hustete. Ein Vergleich der Mittelwerte hätte hier
        // eine Verschlechterung ausgewiesen — die Rangfolge nicht, weil eine von
        // vierundzwanzig Stunden nur ein Rang ist.
        $durations = $this->level(24, 200_000);
        $durations[15] = 8_000_000;

        $this->assertNull(BreakpointScan::find($this->windows($durations)));
    }

    public function test_a_flat_series_yields_nothing(): void
    {
        $this->assertNull(BreakpointScan::find($this->windows($this->level(48, 200_000))));
    }

    public function test_a_change_within_one_class_stays_invisible(): void
    {
        // 120 ms auf 200 ms — beides dieselbe Klasse der Verteilung
        // ({@see DurationHistogram}), also dasselbe p95 auf beiden Seiten. Das
        // ist die bekannte Grenze der Auflösung und keine Nachlässigkeit: sie
        // gilt für jede Auswertung, die über die Verteilung rechnet.
        $windows = $this->windows([
            ...$this->level(12, 120_000),
            ...$this->level(12, 200_000),
        ]);

        $this->assertNull(BreakpointScan::find($windows));
    }

    public function test_a_large_change_on_a_negligible_level_is_not_reported(): void
    {
        // Von 2 ms auf 8 ms ist eine Vervierfachung und trotzdem keine
        // Nachricht: niemand merkt sie.
        $windows = $this->windows([
            ...$this->level(12, 2_000),
            ...$this->level(12, 8_000),
        ]);

        $this->assertNull(BreakpointScan::find($windows));
    }

    public function test_a_short_spike_at_the_end_is_not_a_trend(): void
    {
        // Drei Stunden auf der neuen Höhe: das ist ein Vorfall und noch kein
        // Zustand. Eine Seite muss aus mindestens sechs bewertbaren Fenstern
        // bestehen; die drei langsamen Stunden landen deshalb zwangsläufig in
        // einer Seite, die überwiegend aus schnellen besteht — und genau daran
        // scheitert der Beleg. Für den kurzen Ausschlag ist der
        // Schwellwert-Alarm zuständig (A3), nicht diese Suche.
        $windows = $this->windows([
            ...$this->level(22, 200_000),
            ...$this->level(3, 900_000),
        ]);

        $this->assertNull(BreakpointScan::find($windows));
    }

    public function test_too_few_measurements_yield_nothing(): void
    {
        // Die Mindestdatenmenge aus dem Auftrag. Der Unterschied ist hier so
        // deutlich, dass der Rangsummentest ihn belegt — trotzdem kommt nichts
        // heraus: fünf Messungen je Stunde ergeben über neun Stunden
        // fünfundvierzig, und damit weniger, als eine Seite tragen muss.
        $windows = $this->windows(
            [
                ...$this->level(9, 200_000),
                ...$this->level(9, 900_000),
            ],
            perWindow: 5,
        );

        $this->assertNull(BreakpointScan::find($windows));
    }

    public function test_sparse_hours_do_not_count_as_measurements(): void
    {
        // Fenster mit weniger als fünf Messungen gehen gar nicht erst in die
        // Rechnung: ihr p95 ist die größte der wenigen Messungen und damit ein
        // Zufallswert. Übrig bleiben hier null bewertbare Fenster.
        $windows = $this->windows(
            [
                ...$this->level(24, 200_000),
                ...$this->level(24, 900_000),
            ],
            perWindow: 4,
        );

        $this->assertNull(BreakpointScan::find($windows));
    }

    public function test_the_rank_sum_ignores_the_distance_between_values(): void
    {
        // Der Beleg für die Wahl des Tests: dieselbe Rangfolge ergibt denselben
        // z-Wert, ob die zweite Gruppe doppelt oder tausendfach so groß ist.
        $modest = BreakpointScan::rankSumZ([1, 2, 3, 4, 5, 6], [7, 8, 9, 10, 11, 12]);
        $extreme = BreakpointScan::rankSumZ([1, 2, 3, 4, 5, 6], [7_000, 8_000, 9_000, 10_000, 11_000, 12_000]);

        $this->assertSame($modest, $extreme);
        $this->assertGreaterThan(2.0, $modest);
    }

    public function test_identical_values_carry_no_evidence(): void
    {
        // Lauter Bindungen: die Streuung des Tests ist null, und ohne Streuung
        // gibt es keinen Abstand zum Erwartungswert. Ohne die Korrektur für
        // Bindungen stünde hier eine Zahl, die Aussagekraft behauptet, wo keine
        // ist.
        $this->assertSame(0.0, BreakpointScan::rankSumZ([5, 5, 5, 5], [5, 5, 5, 5]));
    }

    public function test_evidence_grows_with_the_number_of_hours(): void
    {
        // Derselbe Unterschied, einmal aus sechs und einmal aus sechzig
        // Stunden. Genau daran hängt die Zusage „kein Alarm bei zu geringer
        // Aussagekraft": der Schwellwert trennt die beiden Fälle.
        $short = BreakpointScan::rankSumZ([1, 1, 1], [2, 2, 2]);
        $long = BreakpointScan::rankSumZ(array_fill(0, 30, 1), array_fill(0, 30, 2));

        $this->assertLessThan(BreakpointScan::MINIMUM_Z, $short);
        $this->assertGreaterThan(BreakpointScan::MINIMUM_Z, $long);
    }
}
