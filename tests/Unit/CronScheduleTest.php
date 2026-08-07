<?php

namespace Tests\Unit;

use App\Enums\CronIntervalUnit;
use App\Support\Crons\CronSchedule;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Der Zeitplan ist das Stück, an dem die ganze Überwachung hängt: er bestimmt,
 * wann ein Lauf fällig ist, und damit auch, wann einer fehlt. Ein Fehler hier
 * meldet entweder Ausfälle, die keine sind, oder er meldet gar nichts mehr —
 * und Letzteres fällt nicht auf.
 */
class CronScheduleTest extends TestCase
{
    public function test_a_cron_expression_yields_the_next_calendar_slot(): void
    {
        $schedule = CronSchedule::crontab('0 2 * * *');

        $next = $schedule->nextAfter(Carbon::parse('2026-08-07 01:30:00', 'UTC'));

        $this->assertSame('2026-08-07 02:00:00', $next->toDateTimeString());
    }

    /**
     * Der Termin liegt immer **nach** dem Bezugspunkt. Sonst bekäme ein Job,
     * der genau pünktlich meldet, denselben Termin noch einmal — und gälte
     * eine Minute später als verpasst.
     */
    public function test_the_next_slot_is_never_the_reference_point_itself(): void
    {
        $schedule = CronSchedule::crontab('0 2 * * *');

        $next = $schedule->nextAfter(Carbon::parse('2026-08-07 02:00:00', 'UTC'));

        $this->assertSame('2026-08-08 02:00:00', $next->toDateTimeString());
    }

    /**
     * Der eigentliche Grund, warum die Zeitzone am Monitor steht: „täglich
     * 02:00" meint 02:00 dort, wo der Job läuft. In Berlin sind das im Sommer
     * 00:00 UTC.
     */
    public function test_the_schedule_is_evaluated_in_its_own_time_zone(): void
    {
        $schedule = CronSchedule::crontab('0 2 * * *', 'Europe/Berlin');

        $next = $schedule->nextAfter(Carbon::parse('2026-08-07 00:30:00', 'UTC'));

        $this->assertSame('2026-08-08 00:00:00', $next->toDateTimeString());
    }

    /**
     * Und dieselbe Angabe im Winter: derselbe Job, dieselbe Ortszeit, eine
     * Stunde später in UTC. Wer stattdessen mit einem festen Versatz rechnet,
     * verschiebt den Job zweimal im Jahr und meldet ihn dann als verpasst.
     */
    public function test_the_time_zone_follows_daylight_saving(): void
    {
        $schedule = CronSchedule::crontab('0 2 * * *', 'Europe/Berlin');

        $next = $schedule->nextAfter(Carbon::parse('2026-01-15 00:30:00', 'UTC'));

        $this->assertSame('2026-01-15 01:00:00', $next->toDateTimeString());
    }

    public function test_an_interval_counts_from_the_reference_point(): void
    {
        $schedule = CronSchedule::interval(15, CronIntervalUnit::Minute);

        $next = $schedule->nextAfter(Carbon::parse('2026-08-07 10:07:00', 'UTC'));

        $this->assertSame('2026-08-07 10:22:00', $next->toDateTimeString());
    }

    /**
     * Ein Tag ist keine feste Zahl von Sekunden. Über die Zeitumstellung hinweg
     * bleibt „täglich" bei derselben Ortszeit.
     */
    public function test_a_daily_interval_keeps_its_local_time_across_the_clock_change(): void
    {
        $schedule = CronSchedule::interval(1, CronIntervalUnit::Day, 'Europe/Berlin');

        // Der 29.03.2026 ist die Umstellung auf Sommerzeit: 02:00 wird zu 03:00.
        $next = $schedule->nextAfter(Carbon::parse('2026-03-28 22:00:00', 'UTC'));

        $this->assertSame('Europe/Berlin', $next->copy()->setTimezone('Europe/Berlin')->timezoneName);
        $this->assertSame('23:00', $next->copy()->setTimezone('Europe/Berlin')->format('H:i'));
    }

    public function test_seconds_are_dropped(): void
    {
        $schedule = CronSchedule::interval(1, CronIntervalUnit::Hour);

        $next = $schedule->nextAfter(Carbon::parse('2026-08-07 10:07:37', 'UTC'));

        $this->assertSame(0, $next->second);
    }

    public function test_an_unreadable_expression_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CronSchedule::crontab('jeden zweiten Dienstag');
    }

    /**
     * Ein Tippfehler in der Zeitzone eines fremden SDK soll die Überwachung
     * nicht lahmlegen — dann eben UTC.
     */
    public function test_an_unknown_time_zone_falls_back_to_utc(): void
    {
        $schedule = CronSchedule::crontab('0 2 * * *', 'Mittelerde/Auenland');

        $this->assertSame('UTC', $schedule->timezone);
    }

    public function test_shorthand_expressions_are_accepted(): void
    {
        $this->assertTrue(CronSchedule::isValidExpression('@daily'));
        $this->assertTrue(CronSchedule::isValidExpression('*/5 * * * *'));
        $this->assertFalse(CronSchedule::isValidExpression(''));
        $this->assertFalse(CronSchedule::isValidExpression('0 2 * *'));
    }
}
