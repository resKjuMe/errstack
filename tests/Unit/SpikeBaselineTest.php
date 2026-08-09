<?php

namespace Tests\Unit;

use App\Support\Ingest\Spikes\SpikeBaseline;
use PHPUnit\Framework\TestCase;

/**
 * Die Rechnung hinter dem Ausschlag-Schutz (A7): woraus die Schwelle entsteht,
 * ab der gedrosselt wird.
 *
 * Ohne Datenbank, ohne Uhr, ohne Warteschlange — genau dafür ist die Klasse so
 * geschnitten. Eine Entscheidung, die im Ernstfall Daten wegwirft, muss man an
 * Zahlen nachrechnen können; sonst traut ihr niemand, und beim ersten
 * Fehlalarm wird der ganze Schutz abgeschaltet.
 */
class SpikeBaselineTest extends TestCase
{
    public function test_without_enough_history_nothing_is_throttled(): void
    {
        $baseline = SpikeBaseline::fromHistory(array_fill(0, SpikeBaseline::MINIMUM_SAMPLES - 1, 100), 5.0, 10);

        $this->assertFalse($baseline->isReady());
        $this->assertSame(0, $baseline->threshold());
    }

    public function test_the_threshold_is_the_multiple_of_the_baseline(): void
    {
        $baseline = SpikeBaseline::fromHistory($this->minutes(100), 5.0, 10);

        $this->assertTrue($baseline->isReady());
        $this->assertSame(100.0, $baseline->baseline);
        $this->assertSame(500, $baseline->threshold());
    }

    /**
     * Die Untergrenze ist der Schutz des Schutzes: bei einem ruhigen Projekt
     * wäre das Fünffache von zwei gerade zehn, und ein einzelner Ausschlag
     * stünde sofort in der Drosselung.
     */
    public function test_the_floor_wins_when_the_project_is_quiet(): void
    {
        $baseline = SpikeBaseline::fromHistory($this->minutes(2), 5.0, 500);

        $this->assertSame(500, $baseline->threshold());
    }

    /**
     * Der Median ist der Grund, warum der Schutz nach einer Spitze nicht taub
     * wird: eine einzelne Minute mit einer Million Meldungen zöge einen
     * Mittelwert so weit hoch, dass die nächste Flut als normal durchginge.
     */
    public function test_a_single_outlier_does_not_move_the_baseline(): void
    {
        $history = $this->minutes(100);
        $history[0] = 1_000_000;

        $baseline = SpikeBaseline::fromHistory($history, 5.0, 10);

        $this->assertSame(100.0, $baseline->baseline);
    }

    /**
     * Ein Faktor unter 1 wäre eine Schwelle unterhalb des Normalbetriebs — sie
     * würde dauerhaft drosseln. Er wird deshalb angehoben und nicht
     * übernommen.
     */
    public function test_a_factor_below_one_is_lifted(): void
    {
        $baseline = SpikeBaseline::fromHistory($this->minutes(100), 0.5, 0);

        $this->assertSame(100, $baseline->threshold());
    }

    /**
     * Entwarnung erst bei der halben Schwelle: eine Menge, die genau um die
     * Schwelle pendelt, würde sonst im Minutentakt drosseln und entdrosseln —
     * und jeder Wechsel ist eine Benachrichtigung.
     */
    public function test_calming_down_needs_more_than_falling_below_the_threshold(): void
    {
        $baseline = SpikeBaseline::fromHistory($this->minutes(100), 5.0, 10);

        $this->assertFalse($baseline->hasCalmedDown(499));
        $this->assertFalse($baseline->hasCalmedDown(251));
        $this->assertTrue($baseline->hasCalmedDown(250));
    }

    /**
     * Ohne Schwelle gibt es nichts zu halten: liegt zu wenig Verlauf vor, gilt
     * jede Menge als ruhig — sonst bliebe eine Drosselung, die vor dem Verlust
     * des Zwischenspeichers begann, für immer stehen.
     */
    public function test_without_a_threshold_everything_counts_as_calm(): void
    {
        $baseline = SpikeBaseline::fromHistory([1, 2, 3], 5.0, 10);

        $this->assertTrue($baseline->hasCalmedDown(1_000_000));
    }

    /**
     * @return list<int>
     */
    private function minutes(int $quantity): array
    {
        return array_fill(0, SpikeBaseline::MINIMUM_SAMPLES + 5, $quantity);
    }
}
