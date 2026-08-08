<?php

namespace Tests\Feature\Releases;

use App\Enums\AlertMetric;
use App\Enums\IngestType;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use App\Models\MetricAlert;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseSession;
use App\Models\ReleaseSessionCount;
use App\Models\ReleaseSessionUser;
use App\Support\Alerts\MetricSource;
use App\Support\Alerts\MetricWindow;
use App\Support\Releases\Health\ReleaseHealth;
use App\Support\Releases\Health\ReleaseHealthSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Gesundheit einer Auslieferung: wie viele Sitzungen und Nutzer sie
 * überstanden haben.
 *
 * Die eine Zusage, um die sich die meisten Prüfungen hier drehen, ist die aus
 * der Aufgabe: **eine Sitzung wird nie doppelt gezählt** — auch dann nicht,
 * wenn das SDK sie dreimal meldet, und auch dann nicht, wenn die Meldungen sich
 * unterwegs überholen. Alles Weitere hängt daran: eine doppelt gezählte Sitzung
 * verdirbt jede Quote, die aus ihr gerechnet wird.
 */
class ReleaseHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fester Zeitpunkt: die Zahlen liegen in Minuten-Fenstern, und ein Test,
        // der um Mitternacht anders ausgeht, ist keiner.
        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:00', 'UTC'));
    }

    public function test_a_session_is_counted_once_although_the_sdk_reports_it_twice(): void
    {
        $project = Project::factory()->create();

        $this->session($project, ['sid' => 'a1', 'init' => true, 'status' => 'ok', 'seq' => 0]);
        $this->session($project, ['sid' => 'a1', 'status' => 'crashed', 'seq' => 1]);

        $counts = ReleaseSessionCount::query()->sole();

        // Eine Sitzung, ein Absturz — und nicht zwei Sitzungen, von denen eine
        // noch läuft.
        $this->assertSame(1, $counts->session_count);
        $this->assertSame(1, $counts->crashed_count);
        $this->assertSame(0, $counts->errored_count);
        $this->assertSame(1, ReleaseSession::query()->count());
    }

    public function test_a_late_arriving_update_does_not_revive_a_crashed_session(): void
    {
        $project = Project::factory()->create();

        $this->session($project, ['sid' => 'a1', 'init' => true, 'status' => 'ok', 'seq' => 0]);
        $this->session($project, ['sid' => 'a1', 'status' => 'crashed', 'seq' => 7]);
        // Die Zwischenmeldung von vorhin, die sich unterwegs verspätet hat.
        $this->session($project, ['sid' => 'a1', 'status' => 'ok', 'seq' => 3]);

        $counts = ReleaseSessionCount::query()->sole();

        $this->assertSame(1, $counts->session_count);
        $this->assertSame(1, $counts->crashed_count);
    }

    /**
     * Ein SDK, das den Zustand auf `exited` lässt und stattdessen `errors`
     * hochzählt, meint dasselbe wie eines, das `errored` schickt.
     */
    public function test_a_finished_session_with_errors_counts_as_errored(): void
    {
        $project = Project::factory()->create();

        $this->session($project, ['sid' => 'a1', 'status' => 'exited', 'errors' => 2, 'seq' => 1]);

        $counts = ReleaseSessionCount::query()->sole();

        $this->assertSame(1, $counts->session_count);
        $this->assertSame(1, $counts->errored_count);
        $this->assertSame(0, $counts->crashed_count);
    }

    public function test_an_aggregated_bucket_is_counted_as_it_was_sent(): void
    {
        $project = Project::factory()->create();

        $this->sessions($project, [
            'attrs' => ['release' => '1.4.2', 'environment' => 'production'],
            'aggregates' => [[
                'started' => Carbon::now()->subMinutes(5)->toIso8601String(),
                'did' => 'kundin-7',
                'exited' => 41,
                'errored' => 2,
                'crashed' => 1,
                'abnormal' => 0,
            ]],
        ]);

        $counts = ReleaseSessionCount::query()->sole();

        $this->assertSame(44, $counts->session_count);
        $this->assertSame(2, $counts->errored_count);
        $this->assertSame(1, $counts->crashed_count);

        // Für ein Bündel gibt es keine Einzelsitzungen — es gibt dort nichts
        // wiederzufinden.
        $this->assertSame(0, ReleaseSession::query()->count());
        $this->assertSame(1, ReleaseSessionUser::query()->count());
    }

    public function test_a_session_without_a_version_is_not_counted(): void
    {
        $project = Project::factory()->create();

        $this->session($project, ['sid' => 'a1', 'status' => 'crashed'], release: null);

        $this->assertSame(0, ReleaseSessionCount::query()->count());
        $this->assertSame(0, Release::query()->count());
    }

    public function test_the_crash_free_rates_are_read_over_sessions_and_over_people(): void
    {
        $project = Project::factory()->create();

        // Eine Person mit drei Sitzungen, von denen eine abstürzt …
        $this->session($project, ['sid' => 'a1', 'status' => 'exited', 'did' => 'ida']);
        $this->session($project, ['sid' => 'a2', 'status' => 'exited', 'did' => 'ida']);
        $this->session($project, ['sid' => 'a3', 'status' => 'crashed', 'did' => 'ida']);
        // … und eine, bei der alles gut geht.
        $this->session($project, ['sid' => 'b1', 'status' => 'exited', 'did' => 'bo']);

        $summary = $this->summary(Release::query()->sole());

        $this->assertSame(4, $summary->sessions->sessions);
        $this->assertSame(75.0, $summary->crashFreeSessions());

        // Über Menschen gerechnet ist es die Hälfte — und genau darin liegt der
        // Unterschied, den die zweite Zahl sichtbar macht.
        $this->assertSame(2, $summary->users);
        $this->assertSame(1, $summary->crashedUsers);
        $this->assertSame(50.0, $summary->crashFreeUsers());
    }

    public function test_a_version_without_sessions_has_no_rate_instead_of_a_hundred_percent(): void
    {
        $project = Project::factory()->create();
        $release = Release::forVersion($project, '9.9.9');

        $summary = $this->summary($release);

        $this->assertFalse($summary->hasData());
        $this->assertNull($summary->crashFreeSessions());
        $this->assertNull($summary->crashFreeUsers());
    }

    public function test_adoption_is_the_share_of_people_on_this_version(): void
    {
        $project = Project::factory()->create();

        $this->session($project, ['sid' => 'a1', 'did' => 'ida'], release: '1.0.0');
        $this->session($project, ['sid' => 'b1', 'did' => 'bo'], release: '2.0.0');
        $this->session($project, ['sid' => 'c1', 'did' => 'cem'], release: '2.0.0');
        $this->session($project, ['sid' => 'd1', 'did' => 'dana'], release: '2.0.0');

        $newest = Release::query()->where('version', '2.0.0')->sole();

        $summary = $this->summary($newest);

        $this->assertSame(75.0, $summary->adoptionUsers());
        $this->assertSame(75.0, $summary->adoptionSessions());
    }

    public function test_the_comparison_names_the_previous_version(): void
    {
        $project = Project::factory()->create();

        $this->session($project, ['sid' => 'a1', 'status' => 'crashed'], release: '1.0.0');
        $this->session($project, ['sid' => 'b1', 'status' => 'exited'], release: '1.1.0');

        $newest = Release::query()->where('version', '1.1.0')->sole();

        $comparison = app(ReleaseHealth::class)->compare(
            $newest,
            CarbonImmutable::now()->subHour(),
            CarbonImmutable::now()->addMinute(),
        );

        $this->assertSame('1.1.0', $comparison['current']->release->version);
        $this->assertSame('1.0.0', $comparison['previous']?->release->version);
        $this->assertSame(100.0, $comparison['current']->crashFreeSessions());
        $this->assertSame(0.0, $comparison['previous']?->crashFreeSessions());
    }

    /**
     * Die Kennzahl steht den Schwellwert-Alarmen zur Verfügung (A3) — und zwar
     * über dieselben Zahlen, aus denen auch die Übersicht rechnet.
     */
    public function test_a_threshold_alert_can_read_the_crash_free_rate(): void
    {
        $project = Project::factory()->create();

        $this->session($project, ['sid' => 'a1', 'status' => 'exited', 'did' => 'ida']);
        $this->session($project, ['sid' => 'a2', 'status' => 'exited', 'did' => 'ida']);
        $this->session($project, ['sid' => 'a3', 'status' => 'exited', 'did' => 'bo']);
        $this->session($project, ['sid' => 'a4', 'status' => 'crashed', 'did' => 'bo']);

        $alert = MetricAlert::factory()->for($project)->metric(AlertMetric::CrashFreeSessions)->create();
        $window = MetricWindow::endingAt(CarbonImmutable::now()->addMinute(), 60);

        $reading = app(MetricSource::class)->read($alert, $window);

        $this->assertSame(75.0, $reading->value);
        $this->assertSame(4, $reading->samples);

        $alert->metric = AlertMetric::CrashFreeUsers;

        $this->assertSame(50.0, app(MetricSource::class)->read($alert, $window)->value);
    }

    public function test_an_alert_without_sessions_reads_unknown_and_not_a_hundred_percent(): void
    {
        $alert = MetricAlert::factory()->metric(AlertMetric::CrashFreeSessions)->create();

        $reading = app(MetricSource::class)->read(
            $alert,
            MetricWindow::endingAt(CarbonImmutable::now(), 5),
        );

        $this->assertFalse($reading->isKnown());
    }

    /**
     * Die Kennzahlen einer Auslieferung über die letzte Stunde.
     */
    private function summary(Release $release): ReleaseHealthSummary
    {
        return app(ReleaseHealth::class)->summarize(
            $release,
            CarbonImmutable::now()->subHour(),
            CarbonImmutable::now()->addMinute(),
        );
    }

    /**
     * Nimmt eine einzelne Sitzung an und lässt sie durch die Kette laufen.
     *
     * @param  array<string, mixed>  $body
     */
    private function session(Project $project, array $body, ?string $release = '1.0.0'): void
    {
        $body += [
            'started' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'timestamp' => Carbon::now()->toIso8601String(),
            'status' => 'ok',
        ];

        if ($release !== null) {
            $body['attrs'] = ['release' => $release, 'environment' => 'production'];
        }

        $this->ingest($project, $body, IngestType::Session);
    }

    /**
     * Dasselbe für ein Bündel.
     *
     * @param  array<string, mixed>  $body
     */
    private function sessions(Project $project, array $body): void
    {
        $this->ingest($project, $body, IngestType::Sessions);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ingest(Project $project, array $body, IngestType $type): void
    {
        $payload = IngestPayload::factory()
            ->body($body, $type)
            ->create(['project_id' => $project->id]);

        ProcessIngestPayload::dispatch($payload);
    }
}
