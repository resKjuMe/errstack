<?php

namespace Tests\Feature\Crons;

use App\Enums\CronCheckInStatus;
use App\Enums\CronMonitorStatus;
use App\Enums\CronScheduleType;
use App\Models\CronCheckIn;
use App\Models\CronMonitor;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\ProjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Die beiden Wege, auf denen ein Job sich meldet: als `check_in`-Element eines
 * Envelope (was ein Sentry-SDK schickt) und als schlichter HTTP-Aufruf (was ein
 * Shell-Skript kann). Beide müssen dasselbe bewirken — sonst hängt der Zustand
 * eines Jobs davon ab, womit er gebaut wurde.
 */
class CronCheckInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Der Versand läuft ohnehin in der Warteschlange; hier interessiert nur,
        // ob ein Zustellversuch entsteht.
        Queue::fake();
    }

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function monitor(ProjectKey $key, array $attributes = []): CronMonitor
    {
        return CronMonitor::factory()->create([
            'project_id' => $key->project_id,
            'slug' => 'nightly-import',
            'name' => 'Nächtlicher Import',
        ] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $checkIn
     * @return TestResponse<Response>
     */
    private function sendEnvelope(ProjectKey $key, array $checkIn): TestResponse
    {
        $body = implode("\n", [
            '{}',
            '{"type":"check_in"}',
            json_encode($checkIn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ])."\n";

        return $this->call(
            'POST',
            "/api/{$key->project_id}/envelope/",
            server: $this->transformHeadersToServerVars([
                'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key->public_key}",
                'Content-Type' => 'application/x-sentry-envelope',
            ]),
            content: $body,
        );
    }

    public function test_a_check_in_from_an_envelope_marks_the_monitor_as_healthy(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key);

        $response = $this->sendEnvelope($key, [
            'monitor_slug' => 'nightly-import',
            'status' => 'ok',
            'duration' => 12.5,
            'environment' => 'production',
        ]);

        $response->assertStatus(200);

        $monitor->refresh();
        $this->assertSame(CronMonitorStatus::Ok, $monitor->status);
        $this->assertNotNull($monitor->last_check_in_at);

        $checkIn = CronCheckIn::query()->sole();
        $this->assertSame(CronCheckInStatus::Ok, $checkIn->status);
        $this->assertSame(12500, $checkIn->duration_ms);
        $this->assertSame('production', $checkIn->environment);
    }

    /**
     * Beginn und Abschluss gehören über die Kennung zusammen — sonst stünden
     * zwei Ausführungen im Verlauf, wo eine gelaufen ist.
     */
    public function test_in_progress_and_ok_with_the_same_id_are_one_run(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key);
        $checkInId = str_repeat('a', 32);

        $this->sendEnvelope($key, [
            'monitor_slug' => 'nightly-import',
            'status' => 'in_progress',
            'check_in_id' => $checkInId,
        ]);

        $monitor->refresh();
        $this->assertSame(CronMonitorStatus::Running, $monitor->status);
        // Ein begonnener Lauf ist noch kein Ergebnis: er darf keinen der beiden
        // Zähler bewegen.
        $this->assertSame(0, $monitor->consecutive_successes);

        $this->sendEnvelope($key, [
            'monitor_slug' => 'nightly-import',
            'status' => 'ok',
            'check_in_id' => $checkInId,
            'duration' => 3.0,
        ]);

        $this->assertSame(1, CronCheckIn::query()->count());

        $checkIn = CronCheckIn::query()->sole();
        $this->assertSame(CronCheckInStatus::Ok, $checkIn->status);
        $this->assertSame(3000, $checkIn->duration_ms);
        $this->assertNotNull($checkIn->started_at);
        $this->assertNotNull($checkIn->finished_at);

        $monitor->refresh();
        $this->assertSame(1, $monitor->consecutive_successes);
    }

    /**
     * Der bequeme Weg: der Job bringt seinen Zeitplan mit, und die Überwachung
     * entsteht beim ersten Lauf. Der Zeitplan steht ohnehin im Code — ihn ein
     * zweites Mal einzutippen hieße, dass beide Stellen auseinanderlaufen.
     */
    public function test_a_check_in_with_a_config_creates_the_monitor(): void
    {
        $key = $this->key();

        $this->sendEnvelope($key, [
            'monitor_slug' => 'Nightly Import',
            'status' => 'ok',
            'monitor_config' => [
                'schedule' => ['type' => 'crontab', 'value' => '0 2 * * *'],
                'checkin_margin' => 15,
                'max_runtime' => 60,
                'timezone' => 'Europe/Berlin',
                'failure_issue_threshold' => 2,
            ],
        ]);

        $monitor = CronMonitor::query()->sole();

        $this->assertSame('nightly-import', $monitor->slug);
        $this->assertSame(CronScheduleType::Crontab, $monitor->schedule_type);
        $this->assertSame('0 2 * * *', $monitor->schedule_expression);
        $this->assertSame('Europe/Berlin', $monitor->timezone);
        $this->assertSame(15, $monitor->checkin_margin_minutes);
        $this->assertSame(60, $monitor->max_runtime_minutes);
        $this->assertSame(2, $monitor->failure_tolerance);
        $this->assertNotNull($monitor->next_due_at);
    }

    /**
     * Ohne Zeitplan wird nichts angelegt: ein Monitor ohne Termin könnte nie
     * feststellen, dass eine Ausführung ausgeblieben ist — und wäre damit genau
     * das, was hier fehlt.
     */
    public function test_a_check_in_for_an_unknown_monitor_is_ignored(): void
    {
        $key = $this->key();

        $response = $this->sendEnvelope($key, [
            'monitor_slug' => 'gibt-es-nicht',
            'status' => 'ok',
        ]);

        // Die Antwort bleibt 200: der Envelope kann weitere Elemente tragen,
        // und ein SDK schickt einen abgewiesenen nicht noch einmal.
        $response->assertStatus(200);

        $this->assertSame(0, CronMonitor::query()->count());
        $this->assertSame(0, CronCheckIn::query()->count());
    }

    /**
     * Was das SDK nicht mitschickt, bleibt stehen. Sonst setzte jeder Lauf eine
     * in der Oberfläche eingestellte Toleranz wieder zurück.
     */
    public function test_a_config_only_changes_the_fields_it_names(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key, ['checkin_margin_minutes' => 42]);

        $this->sendEnvelope($key, [
            'monitor_slug' => 'nightly-import',
            'status' => 'ok',
            'monitor_config' => [
                'schedule' => ['type' => 'interval', 'value' => 30, 'unit' => 'minute'],
            ],
        ]);

        $monitor->refresh();
        $this->assertSame(30, $monitor->interval_value);
        $this->assertSame(42, $monitor->checkin_margin_minutes);
    }

    public function test_the_simple_http_endpoint_records_a_successful_run(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key);

        $response = $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/");

        $response->assertStatus(202)->assertJsonPath('accepted', true);

        $monitor->refresh();
        $this->assertSame(CronMonitorStatus::Ok, $monitor->status);
        $this->assertSame(CronCheckInStatus::Ok, CronCheckIn::query()->sole()->status);
    }

    public function test_the_simple_http_endpoint_accepts_a_reported_failure(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key);

        $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/?status=error");

        $monitor->refresh();
        $this->assertSame(CronMonitorStatus::Error, $monitor->status);
        $this->assertSame(1, $monitor->consecutive_failures);
    }

    /**
     * Ein Job darf seinen eigenen Ausfall nicht melden — `missed` und `timeout`
     * sind unsere Feststellung. Ein solcher Aufruf gilt deshalb als das, was er
     * praktisch ist: ein Lebenszeichen.
     */
    public function test_a_status_only_we_may_set_is_not_accepted_from_outside(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key);

        $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/?status=missed");

        $monitor->refresh();
        $this->assertSame(CronMonitorStatus::Ok, $monitor->status);
    }

    public function test_the_simple_http_endpoint_refuses_a_foreign_key(): void
    {
        $key = $this->key();
        $this->monitor($key);
        $foreign = $this->key();

        $response = $this->get("/api/{$key->project_id}/cron/nightly-import/{$foreign->public_key}/");

        $response->assertStatus(401);
        $this->assertSame(0, CronCheckIn::query()->count());
    }

    /**
     * Abgeschaltet heißt abgeschaltet — auch dann, wenn der Job munter
     * weitermeldet.
     */
    public function test_a_disabled_monitor_records_nothing(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key, ['is_active' => false]);

        $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/");

        $this->assertSame(0, CronCheckIn::query()->count());
        $this->assertSame(CronMonitorStatus::Unknown, $monitor->refresh()->status);
    }

    /**
     * Die Fehlertoleranz: erst der zweite Fehlschlag in Folge weckt jemanden.
     */
    public function test_the_alert_waits_for_the_failure_tolerance(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key, ['failure_tolerance' => 2]);

        NotificationChannel::factory()->create([
            'organization_id' => $monitor->project->organization_id,
            'is_active' => true,
        ]);

        $url = "/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/?status=error";

        $this->get($url);
        $this->assertSame(0, NotificationDelivery::query()->count());

        $this->get($url);
        $this->assertSame(1, NotificationDelivery::query()->count());

        // Und danach nicht im Minutentakt erneut: die Störung ist gemeldet.
        $this->get($url);
        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    /**
     * Nach der Störung die Entwarnung. Ohne sie muss jeder Empfänger selbst
     * nachsehen, ob es sich erledigt hat — und genau das unterbleibt dann.
     */
    public function test_a_successful_run_after_an_alert_sends_the_all_clear(): void
    {
        $key = $this->key();
        $monitor = $this->monitor($key);

        NotificationChannel::factory()->create([
            'organization_id' => $monitor->project->organization_id,
            'is_active' => true,
        ]);

        $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/?status=error");
        $this->assertNotNull($monitor->refresh()->alerted_at);

        $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/");

        $this->assertNull($monitor->refresh()->alerted_at);
        $this->assertSame(2, NotificationDelivery::query()->count());
    }

    /**
     * Der Termin wird erst mit dem Abschluss fortgeschrieben — sonst wäre ein
     * begonnener Lauf seine eigene Vorgeschichte, und ein hängender Job sähe
     * aus, als hätte er seinen Termin eingehalten.
     */
    public function test_the_next_slot_moves_only_once_a_run_is_finished(): void
    {
        Carbon::setTestNow('2026-08-07 01:00:00');

        $key = $this->key();
        $monitor = $this->monitor($key, ['schedule_expression' => '0 2 * * *']);
        $monitor->scheduleNextDue();
        $monitor->save();

        $due = $monitor->next_due_at;
        $this->assertSame('2026-08-07 02:00:00', $due->toDateTimeString());

        // Der Job beginnt pünktlich …
        Carbon::setTestNow('2026-08-07 02:00:10');
        $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/?status=in_progress");
        $this->assertTrue($due->equalTo($monitor->refresh()->next_due_at));

        // … und meldet sich eine Minute später fertig. Erst jetzt rückt der
        // Termin auf den Lauf am Folgetag.
        Carbon::setTestNow('2026-08-07 02:01:00');
        $this->get("/api/{$key->project_id}/cron/nightly-import/{$key->public_key}/");
        $this->assertSame('2026-08-08 02:00:00', $monitor->refresh()->next_due_at->toDateTimeString());

        Carbon::setTestNow();
    }
}
