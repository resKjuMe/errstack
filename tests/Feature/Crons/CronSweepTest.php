<?php

namespace Tests\Feature\Crons;

use App\Enums\CronCheckInStatus;
use App\Enums\CronMonitorStatus;
use App\Models\CronCheckIn;
use App\Models\CronMonitor;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Support\Crons\CronMonitorSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Der Teil, um den es bei einer Cronjob-Überwachung eigentlich geht: die
 * Feststellung, dass **nichts** gekommen ist. Alles andere passiert, weil sich
 * jemand meldet — hier passiert etwas, weil niemand es tut.
 */
class CronSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function sweep(): CronMonitorSweep
    {
        return app(CronMonitorSweep::class);
    }

    /**
     * Ein Monitor, dessen Termin um 02:00 lag und dessen Toleranz abgelaufen
     * ist.
     */
    private function overdueMonitor(array $attributes = []): CronMonitor
    {
        $project = Project::factory()->create();

        $monitor = CronMonitor::factory()->create([
            'project_id' => $project->id,
            'slug' => 'nightly-import',
            'schedule_expression' => '0 2 * * *',
            'checkin_margin_minutes' => 15,
        ] + $attributes);

        $monitor->next_due_at = Carbon::parse('2026-08-07 02:00:00');
        $monitor->save();

        return $monitor;
    }

    /**
     * Der Fall aus der Aufgabenstellung: ein Job mit Zeitplan „täglich 02:00"
     * und 15 Minuten Toleranz meldet sich nicht.
     */
    public function test_a_run_that_never_arrived_is_recorded_as_missed(): void
    {
        Carbon::setTestNow('2026-08-07 02:20:00');

        $monitor = $this->overdueMonitor();

        $this->assertSame(['missed' => 1, 'timeout' => 0], $this->sweep()->run());

        $checkIn = CronCheckIn::query()->sole();
        $this->assertSame(CronCheckInStatus::Missed, $checkIn->status);
        $this->assertSame('2026-08-07 02:00:00', $checkIn->expected_at->toDateTimeString());
        // Ein verpasster Lauf hat nie begonnen — ein Startzeitpunkt wäre eine
        // Erfindung.
        $this->assertNull($checkIn->started_at);

        $monitor->refresh();
        $this->assertSame(CronMonitorStatus::Missed, $monitor->status);
        $this->assertSame(1, $monitor->consecutive_failures);

        Carbon::setTestNow();
    }

    /**
     * Innerhalb der Toleranz passiert nichts. Ein Job startet nie auf die
     * Sekunde, und ein Alarm um 02:01 wäre einer, den niemand mehr ernst nimmt.
     */
    public function test_nothing_happens_inside_the_grace_period(): void
    {
        Carbon::setTestNow('2026-08-07 02:10:00');

        $this->overdueMonitor();

        $this->assertSame(['missed' => 0, 'timeout' => 0], $this->sweep()->run());
        $this->assertSame(0, CronCheckIn::query()->count());

        Carbon::setTestNow();
    }

    /**
     * Derselbe verpasste Termin darf nicht in jeder Minute erneut auffallen —
     * sonst steht der Verlauf innerhalb eines Tages voll mit derselben Zeile.
     */
    public function test_the_same_missed_slot_is_recorded_only_once(): void
    {
        Carbon::setTestNow('2026-08-07 02:20:00');

        $this->overdueMonitor();

        $this->sweep()->run();
        $this->sweep()->run();
        $this->sweep()->run();

        $this->assertSame(1, CronCheckIn::query()->count());

        Carbon::setTestNow();
    }

    /**
     * Ein abgeschalteter Monitor stellt nichts fest.
     */
    public function test_a_disabled_monitor_is_left_alone(): void
    {
        Carbon::setTestNow('2026-08-07 02:20:00');

        $this->overdueMonitor(['is_active' => false]);

        $this->assertSame(['missed' => 0, 'timeout' => 0], $this->sweep()->run());

        Carbon::setTestNow();
    }

    /**
     * Ein laufender Job ist kein verpasster Termin: er hat sich gemeldet, er ist
     * nur noch nicht fertig.
     */
    public function test_a_running_execution_is_not_a_missed_slot(): void
    {
        Carbon::setTestNow('2026-08-07 02:20:00');

        $monitor = $this->overdueMonitor();

        CronCheckIn::factory()->running()->create([
            'cron_monitor_id' => $monitor->id,
            'project_id' => $monitor->project_id,
            'started_at' => Carbon::parse('2026-08-07 02:01:00'),
        ]);

        $result = $this->sweep()->run();

        $this->assertSame(0, $result['missed']);
        $this->assertSame(CronCheckInStatus::InProgress, CronCheckIn::query()->sole()->status);

        Carbon::setTestNow();
    }

    /**
     * Und ab wann er doch einer ist: nach Ablauf der erlaubten Laufzeit. Ohne
     * diese Grenze bliebe ein hängender Job für immer „läuft" — der Ausfall,
     * der am schwersten auffällt.
     */
    public function test_an_execution_that_runs_too_long_is_marked_as_timed_out(): void
    {
        Carbon::setTestNow('2026-08-07 03:00:00');

        $monitor = $this->overdueMonitor(['max_runtime_minutes' => 30]);

        $checkIn = CronCheckIn::factory()->running()->create([
            'cron_monitor_id' => $monitor->id,
            'project_id' => $monitor->project_id,
            'started_at' => Carbon::parse('2026-08-07 02:01:00'),
        ]);

        $result = $this->sweep()->run();

        $this->assertSame(1, $result['timeout']);
        $this->assertSame(CronCheckInStatus::Timeout, $checkIn->refresh()->status);
        $this->assertNotNull($checkIn->finished_at);

        $monitor->refresh();
        $this->assertSame(CronMonitorStatus::Timeout, $monitor->status);

        Carbon::setTestNow();
    }

    public function test_an_execution_inside_its_run_time_is_left_running(): void
    {
        Carbon::setTestNow('2026-08-07 02:20:00');

        $monitor = $this->overdueMonitor(['max_runtime_minutes' => 60]);

        $checkIn = CronCheckIn::factory()->running()->create([
            'cron_monitor_id' => $monitor->id,
            'project_id' => $monitor->project_id,
            'started_at' => Carbon::parse('2026-08-07 02:01:00'),
        ]);

        $this->sweep()->run();

        $this->assertSame(CronCheckInStatus::InProgress, $checkIn->refresh()->status);

        Carbon::setTestNow();
    }

    /**
     * Ein verpasster Lauf löst denselben Alarm aus wie ein gemeldeter
     * Fehlschlag — und respektiert dieselbe Fehlertoleranz.
     */
    public function test_a_missed_run_alerts_once_the_tolerance_is_used_up(): void
    {
        Carbon::setTestNow('2026-08-07 02:20:00');

        $monitor = $this->overdueMonitor(['failure_tolerance' => 2]);

        NotificationChannel::factory()->create([
            'organization_id' => $monitor->project->organization_id,
            'is_active' => true,
        ]);

        $this->sweep()->run();
        $this->assertSame(0, NotificationDelivery::query()->count());

        // Der nächste Termin ist der Lauf am Folgetag; auch der bleibt aus.
        Carbon::setTestNow('2026-08-08 02:20:00');
        $this->sweep()->run();

        $this->assertSame(2, $monitor->refresh()->consecutive_failures);
        $this->assertSame(1, NotificationDelivery::query()->count());

        Carbon::setTestNow();
    }

    /**
     * Die Prüfung ist ein Artisan-Befehl, weil sie aus dem Zeitplan der
     * Anwendung kommt. Läuft der nicht, meldet die Überwachung still nichts
     * mehr — deshalb ist auch der Befehl selbst geprüft.
     */
    public function test_the_console_command_runs_the_sweep(): void
    {
        Carbon::setTestNow('2026-08-07 02:20:00');

        $this->overdueMonitor();

        $this->artisan('crons:sweep')->assertSuccessful();

        $this->assertSame(1, CronCheckIn::query()->count());

        Carbon::setTestNow();
    }
}
