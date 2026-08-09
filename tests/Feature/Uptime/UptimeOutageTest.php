<?php

namespace Tests\Feature\Uptime;

use App\Enums\IssueCategory;
use App\Enums\UptimeCheckOutcome;
use App\Enums\UptimeStatus;
use App\Jobs\CheckUptimeMonitor;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use App\Models\UptimeOutage;
use App\Support\Uptime\UptimeStats;
use App\Support\Uptime\UptimeSweep;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Der Teil, um den es bei einer Erreichbarkeits-Überwachung eigentlich geht:
 * dass ein Ausfall **als Vorfall** festgehalten wird — mit Beginn, Ende und
 * Dauer —, dass er gemeldet wird und als Fehler-Eintrag auftaucht, und dass ein
 * Aussetzer nichts davon auslöst.
 */
class UptimeOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        // Die Zustellung der Meldungen läuft über Aufträge; geprüft wird hier,
        // dass sie entstehen, nicht dass ein Webhook antwortet.
        Queue::fake();

        Http::fake(function (): PromiseInterface {
            [$body, $status] = count($this->answers) > 1
                ? array_shift($this->answers)
                : $this->answers[0];

            return Http::response($body, $status);
        });
    }

    /**
     * Ein Monitor mit einem Takt von einer Minute — der Fall aus der
     * Aufgabenstellung.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function monitor(array $attributes = []): UptimeMonitor
    {
        $project = Project::factory()->create();

        return UptimeMonitor::factory()->for($project)->create($attributes + [
            'name' => 'Startseite',
            'slug' => 'startseite',
            'url' => 'https://ziel.test/',
            'interval_seconds' => 60,
        ]);
    }

    /**
     * Führt die Prüfung so aus, wie sie im Betrieb läuft: über den Job.
     */
    private function check(UptimeMonitor $monitor): void
    {
        app()->call([new CheckUptimeMonitor($monitor->id), 'handle']);

        $monitor->refresh();
    }

    /**
     * Die beiden Schlüssel, die eine Verlaufszeile an ihren Monitor binden —
     * ausgeschrieben statt über die Beziehung der Fabrik, damit im Test
     * sichtbar bleibt, zu welchem Projekt die Messung gehört.
     *
     * @return array<string, mixed>
     */
    private function checkFor(UptimeMonitor $monitor, Carbon $at): array
    {
        return [
            'uptime_monitor_id' => $monitor->id,
            'project_id' => $monitor->project_id,
            'checked_at' => $at,
        ];
    }

    /**
     * Was das Ziel als Nächstes antwortet, als Warteschlange: das letzte
     * Element bleibt stehen und beantwortet alle weiteren Anfragen.
     *
     * **Ein einziger Stub für den ganzen Test**, und das ist kein Geschmack:
     * `Http::fake()` **ergänzt** seine Stubs, statt sie zu ersetzen, und der
     * zuerst registrierte greift. Ein zweiter Aufruf mitten im Test änderte
     * deshalb gar nichts — das Ziel bliebe erreichbar, und der Test prüfte
     * einen Ausfall, den es nie gab.
     *
     * @var list<array{0: string, 1: int}>
     */
    private array $answers = [['ok', 200]];

    /**
     * @param  array{0: string, 1: int}  ...$answers
     */
    private function answers(array ...$answers): void
    {
        $this->answers = array_values($answers);
    }

    private function up(): void
    {
        $this->answers(['ok', 200]);
    }

    private function down(): void
    {
        $this->answers(['weg', 503]);
    }

    /**
     * Der Ablauf aus der Testanleitung: eine erreichbare Adresse, dann
     * unerreichbar gemacht, zwei Minuten später nachgesehen. Der Monitor steht
     * auf „ausgefallen", der Ausfall ist protokolliert.
     */
    public function test_an_unreachable_target_becomes_a_recorded_outage(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');

        $monitor = $this->monitor();

        $this->up();
        $this->check($monitor);

        $this->assertSame(UptimeStatus::Up, $monitor->status);
        $this->assertSame(0, $monitor->outages()->count());

        Carbon::setTestNow('2026-08-11 10:01:00');

        $this->down();
        $this->check($monitor);

        $this->assertSame(UptimeStatus::Down, $monitor->status);

        $outage = $monitor->outages()->sole();

        $this->assertSame(UptimeCheckOutcome::StatusMismatch, $outage->outcome);
        $this->assertSame(503, $outage->http_status);
        $this->assertTrue($outage->isRunning());
        $this->assertNull($outage->ended_at);
        $this->assertSame('2026-08-11 10:01:00', $outage->started_at->format('Y-m-d H:i:s'));
    }

    /**
     * Beginn, Ende und Dauer — die drei Angaben, die einen Vorfall ausmachen.
     */
    public function test_an_outage_has_start_end_and_duration(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');

        $monitor = $this->monitor();

        $this->down();
        $this->check($monitor);

        Carbon::setTestNow('2026-08-11 10:05:00');

        $this->up();
        $this->check($monitor);

        $outage = $monitor->outages()->sole();

        $this->assertFalse($outage->isRunning());
        $this->assertSame('2026-08-11 10:00:00', $outage->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-11 10:05:00', $outage->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame(300, $outage->duration_seconds);
        $this->assertSame(UptimeStatus::Up, $monitor->status);
    }

    /**
     * **Der Kern der Fehlalarm-Vermeidung.** Ein einzelner Aussetzer wird von
     * der Bestätigungsprüfung abgefangen: kein Ausfall, keine Meldung, kein
     * Eintrag.
     */
    public function test_a_hiccup_confirmed_as_healthy_produces_no_outage(): void
    {
        $monitor = $this->monitor(['confirmation_retries' => 1, 'confirmation_delay_seconds' => 0]);

        // Ein Aussetzer, danach wieder in Ordnung.
        $this->answers(['', 503], ['ok', 200]);

        $this->check($monitor);

        $this->assertSame(UptimeStatus::Up, $monitor->status);
        $this->assertSame(0, $monitor->outages()->count());
        $this->assertSame(0, Issue::query()->count());

        // Die Messung steht trotzdem im Verlauf — mit dem Vermerk, dass zwei
        // Anläufe nötig waren.
        $this->assertSame(2, $monitor->checks()->sole()->attempts);
    }

    /**
     * Unterhalb der Schwelle gibt es noch keinen Ausfall, aber auch nicht
     * „alles in Ordnung" — der Zwischenzustand ist die Minute, in der man noch
     * etwas tun kann.
     */
    public function test_below_the_threshold_the_monitor_is_only_flagged(): void
    {
        $monitor = $this->monitor(['failure_threshold' => 2]);

        $this->down();
        $this->check($monitor);

        $this->assertSame(UptimeStatus::Degraded, $monitor->status);
        $this->assertSame(0, $monitor->outages()->count());

        $this->check($monitor);

        $this->assertSame(UptimeStatus::Down, $monitor->status);
        $this->assertSame(1, $monitor->outages()->count());
    }

    /**
     * Ein durchgehender Ausfall bleibt **ein** Vorfall und wird nicht bei jeder
     * Prüfung neu eröffnet — sonst stünden in der Liste hundert Zeilen, die
     * alle dasselbe sagen.
     */
    public function test_a_lasting_outage_stays_one_incident(): void
    {
        $monitor = $this->monitor();

        $this->down();

        $this->check($monitor);
        $this->check($monitor);
        $this->check($monitor);

        $outage = $monitor->outages()->sole();

        $this->assertSame(3, $outage->failed_checks);
    }

    /**
     * Der Vorfall erscheint zusätzlich als Fehler-Eintrag — dort, wo ohnehin
     * nachgesehen wird.
     */
    public function test_an_outage_shows_up_as_an_issue(): void
    {
        $monitor = $this->monitor();

        $this->down();
        $this->check($monitor);

        $issue = Issue::query()->sole();

        $this->assertSame(IssueCategory::Error, $issue->category);
        $this->assertStringContainsString('Startseite', (string) $issue->title);
        $this->assertSame('https://ziel.test/', $issue->culprit);
        $this->assertSame(1, $issue->times_seen);

        $this->assertSame($issue->id, $monitor->outages()->sole()->issue_id);
    }

    /**
     * Ein zweiter Ausfall desselben Ziels landet am **selben** Eintrag und
     * erhöht dessen Häufigkeit. Ein zweiter Eintrag wäre genau die Flut, gegen
     * die es die Gruppierung gibt.
     */
    public function test_a_second_outage_counts_at_the_same_issue(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');

        $monitor = $this->monitor();

        $this->down();
        $this->check($monitor);

        Carbon::setTestNow('2026-08-11 10:05:00');
        $this->up();
        $this->check($monitor);

        Carbon::setTestNow('2026-08-11 11:00:00');
        $this->down();
        $this->check($monitor);

        $this->assertSame(1, Issue::query()->count());
        $this->assertSame(2, Issue::query()->sole()->times_seen);
        $this->assertSame(2, $monitor->outages()->count());
    }

    /**
     * Ausfall und Entwarnung gehen über dieselben Kanäle raus wie jeder andere
     * Alarm — ein eigener Versandweg wäre eine zweite Stelle, an der jemand
     * seine Ruhezeiten einstellen müsste.
     */
    public function test_the_outage_and_the_recovery_are_reported(): void
    {
        $monitor = $this->monitor();

        NotificationChannel::factory()->create([
            'organization_id' => $monitor->project->organization_id,
            'is_active' => true,
        ]);

        $this->down();
        $this->check($monitor);

        $this->assertSame(1, NotificationDelivery::query()->count());

        $this->up();
        $this->check($monitor);

        $this->assertSame(2, NotificationDelivery::query()->count());
    }

    /**
     * Die Verfügbarkeitsquote zählt Prüfungen — ohne Nenner gibt es keine
     * Quote, und eine Null wäre dort eine erfundene Angabe.
     */
    public function test_the_availability_is_computed_from_the_checks(): void
    {
        $monitor = $this->monitor();

        UptimeCheck::factory()->count(9)->create($this->checkFor($monitor, now()->subMinutes(30)));
        UptimeCheck::factory()->failed()->create($this->checkFor($monitor, now()->subMinutes(30)));

        $availability = (new UptimeStats)->availability($monitor);

        $this->assertSame(90.0, $availability['day']['availability']);
        $this->assertSame(10, $availability['day']['checks']);
        $this->assertSame(1, $availability['day']['failures']);

        // Ein Monitor ohne Messung im Fenster hat keine Quote — nicht null
        // Prozent.
        $fresh = $this->monitor(['slug' => 'zweite']);

        $this->assertNull((new UptimeStats)->availability($fresh)['day']['availability']);
    }

    /**
     * Der Antwortzeit-Verlauf: älteste zuerst, gescheiterte Messungen als
     * Lücke. Eine glatte Kurve über einen Ausfall hinweg wäre genau die
     * Auskunft, die hier fehlen soll.
     */
    public function test_the_response_time_history_keeps_the_gaps(): void
    {
        $monitor = $this->monitor();

        UptimeCheck::factory()->create($this->checkFor($monitor, now()->subMinutes(3)) + ['response_time_ms' => 120]);
        UptimeCheck::factory()->failed()->create($this->checkFor($monitor, now()->subMinutes(2)));
        UptimeCheck::factory()->create($this->checkFor($monitor, now()->subMinute()) + ['response_time_ms' => 90]);

        $history = (new UptimeStats)->responseTimes($monitor);

        $this->assertSame([120, null, 90], array_column($history, 'ms'));
        $this->assertSame([true, false, true], array_column($history, 'ok'));
    }

    /**
     * Die Zusage der Aufgabe: geprüft wird ausschließlich in der Warteschlange.
     * Der Sweep reiht ein und prüft nicht selbst — und er reserviert die
     * Fälligkeit sofort, damit derselbe Monitor eine Minute später nicht ein
     * zweites Mal drankommt.
     */
    public function test_the_sweep_only_queues_and_reserves_the_next_slot(): void
    {
        $due = $this->monitor(['slug' => 'faellig', 'next_check_at' => now()->subMinute()]);
        $this->monitor(['slug' => 'spaeter', 'next_check_at' => now()->addMinutes(5)]);
        $this->monitor(['slug' => 'aus', 'is_active' => false, 'next_check_at' => now()->subMinute()]);

        $queued = (new UptimeSweep)->run();

        $this->assertSame(1, $queued);

        Queue::assertPushed(CheckUptimeMonitor::class, 1);
        Queue::assertPushed(fn (CheckUptimeMonitor $job): bool => $job->monitorId === $due->id);

        $due->refresh();

        $this->assertTrue($due->next_check_at->greaterThan(now()));

        // Und nichts gemessen: das ist der Job.
        $this->assertSame(0, UptimeCheck::query()->count());
    }

    /**
     * Ein zwischenzeitlich abgeschalteter Monitor wird nicht geprüft, auch wenn
     * sein Auftrag schon in der Schlange stand — der Normalfall, wenn jemand
     * während einer Störung die Überwachung stilllegt.
     */
    public function test_a_disabled_monitor_is_skipped_by_the_job(): void
    {
        $monitor = $this->monitor(['is_active' => false]);

        $this->down();
        $this->check($monitor);

        $this->assertSame(0, UptimeCheck::query()->count());
        $this->assertSame(0, UptimeOutage::query()->count());
    }
}
