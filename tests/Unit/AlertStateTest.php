<?php

namespace Tests\Unit;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Support\Alerts\AlertState;
use Tests\TestCase;

/**
 * Die Zustandsmaschine für sich — ohne Datenbank, ohne Uhr, ohne Versand.
 *
 * Sie ist der Teil, an dem sich Fehler verstecken: eine Hysterese, die in eine
 * Richtung klemmt, fällt im Betrieb erst auf, wenn ein Alarm nie wieder aufgeht.
 */
class AlertStateTest extends TestCase
{
    private function next(
        AlertStatus $current,
        float $value,
        AlertDirection $direction = AlertDirection::Above,
        ?float $warning = 10.0,
        ?float $critical = 20.0,
        ?float $resolve = null,
    ): AlertStatus {
        return AlertState::next($current, $value, $direction, $warning, $critical, $resolve);
    }

    public function test_a_value_within_the_range_stays_ok(): void
    {
        $this->assertSame(AlertStatus::Ok, $this->next(AlertStatus::Ok, 5.0));
    }

    public function test_the_threshold_itself_already_counts_as_breached(): void
    {
        // „Mehr als 10" wird von niemandem als „ab 11" gelesen, und eine
        // Schwelle, die genau bei ihrem Wert nicht greift, ist die Sorte
        // Feinheit, die man im Ernstfall nicht bemerkt.
        $this->assertSame(AlertStatus::Warning, $this->next(AlertStatus::Ok, 10.0));
    }

    public function test_the_critical_threshold_wins_over_the_warning(): void
    {
        $this->assertSame(AlertStatus::Critical, $this->next(AlertStatus::Ok, 25.0));
    }

    public function test_without_a_resolve_threshold_the_alert_clears_below_the_warning(): void
    {
        $this->assertSame(AlertStatus::Ok, $this->next(AlertStatus::Critical, 9.0));
    }

    public function test_the_resolve_threshold_holds_the_alert_until_the_value_really_clears(): void
    {
        // Zwischen Auflösungs- und Warnschwelle: nicht mehr auslösend, aber
        // auch noch nicht aufgelöst.
        $this->assertSame(
            AlertStatus::Warning,
            $this->next(AlertStatus::Critical, 7.0, resolve: 5.0),
        );

        $this->assertSame(
            AlertStatus::Ok,
            $this->next(AlertStatus::Critical, 4.0, resolve: 5.0),
        );
    }

    public function test_the_resolve_threshold_itself_is_not_enough(): void
    {
        // Der Wert muss die Grenze wirklich hinter sich lassen — sonst pendelt
        // ein Wert, der genau auf der Auflösungsschwelle liegt, zwischen Alarm
        // und Entwarnung.
        $this->assertSame(
            AlertStatus::Warning,
            $this->next(AlertStatus::Critical, 5.0, resolve: 5.0),
        );
    }

    public function test_an_alert_without_a_warning_threshold_is_held_at_critical(): void
    {
        // Es gibt keine Warnstufe, die jemand eingerichtet hätte — dann darf die
        // Hysterese auch keine erfinden.
        $this->assertSame(
            AlertStatus::Critical,
            $this->next(AlertStatus::Critical, 7.0, warning: null, resolve: 5.0),
        );
    }

    public function test_the_hysteresis_does_not_apply_to_an_alert_that_is_calm(): void
    {
        // Ein ruhiger Alarm bleibt ruhig, auch wenn der Wert die
        // Auflösungsschwelle noch nicht unterschritten hat: sie beschreibt das
        // Ende eines Alarms, nicht seinen Anfang.
        $this->assertSame(
            AlertStatus::Ok,
            $this->next(AlertStatus::Ok, 7.0, resolve: 5.0),
        );
    }

    public function test_a_falling_metric_fires_when_it_drops_below(): void
    {
        // Ein einbrechender Durchsatz ist der Ausfall, den man am spätesten von
        // selbst bemerkt.
        $this->assertSame(
            AlertStatus::Critical,
            $this->next(AlertStatus::Ok, 3.0, AlertDirection::Below, warning: 10.0, critical: 5.0),
        );

        $this->assertSame(
            AlertStatus::Ok,
            $this->next(AlertStatus::Critical, 11.0, AlertDirection::Below, warning: 10.0, critical: 5.0),
        );
    }

    public function test_the_hysteresis_runs_the_other_way_for_a_falling_metric(): void
    {
        $this->assertSame(
            AlertStatus::Warning,
            $this->next(
                AlertStatus::Critical,
                12.0,
                AlertDirection::Below,
                warning: 10.0,
                critical: 5.0,
                resolve: 15.0,
            ),
        );

        $this->assertSame(
            AlertStatus::Ok,
            $this->next(
                AlertStatus::Critical,
                16.0,
                AlertDirection::Below,
                warning: 10.0,
                critical: 5.0,
                resolve: 15.0,
            ),
        );
    }
}
