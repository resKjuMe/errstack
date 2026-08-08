<?php

namespace Tests\Feature\Performance;

use App\Enums\IngestType;
use App\Enums\IssueCategory;
use App\Enums\PerformanceProblem;
use App\Enums\QueueName;
use App\Jobs\DetectPerformanceIssues;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\PerformanceDetection;
use App\Models\PerformanceSetting;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionSpan;
use App\Models\User;
use App\Support\Performance\Detection\PerformanceScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Performance\TransactionPayload;
use Tests\TestCase;

/**
 * Die Leistungsprobleme von der Erkennung bis zur Liste.
 *
 * Der Fall aus der Aufgabe ist der erste Test: ein Endpunkt fragt in einer
 * Schleife fünfzig Mal dasselbe ab, und danach steht in der Liste ein Eintrag
 * „N+1-Abfragen" mit Beispiel und verlorener Zeit. Die übrigen Tests sichern
 * die Zusagen ringsum — dass sich nichts doppelt zählt, dass Fehler und
 * Leistungsprobleme getrennt bleiben und dass die Schwellen je Projekt wirken.
 */
class PerformanceIssueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    public function test_a_loop_of_similar_queries_becomes_an_n_plus_one_issue(): void
    {
        [, , $project] = $this->context();

        $transaction = $this->traceWithNPlusOne($project);

        PerformanceScanner::fromConfig()->scan($transaction);

        $issue = Issue::query()->sole();

        $this->assertSame(IssueCategory::Performance, $issue->category);
        $this->assertSame(PerformanceProblem::NPlusOneQueries->value, $issue->type);
        $this->assertStringContainsString('select * from items where order_id = ?', (string) $issue->title);
        $this->assertSame('GET /bestellungen', $issue->culprit);
        $this->assertSame(1, $issue->times_seen);

        // Fünfzig Abfragen zu je zwei Millisekunden, davon 49 vermeidbar.
        $this->assertSame(49 * 2_000, $issue->time_lost_us);

        $detection = PerformanceDetection::query()->sole();

        $this->assertSame($issue->id, $detection->issue_id);
        $this->assertSame($transaction->trace_id, $detection->trace_id);
        $this->assertCount(50, $detection->span_ids);
        $this->assertSame(50, $detection->evidence['repeats']);
    }

    public function test_scanning_the_same_trace_twice_does_not_count_twice(): void
    {
        [, , $project] = $this->context();

        $transaction = $this->traceWithNPlusOne($project);

        $scanner = PerformanceScanner::fromConfig();

        $this->assertSame(1, $scanner->scan($transaction));
        // Ein zweiter Anlauf ist erlaubt — der Auftrag darf mehrfach kommen —
        // und darf nichts bewegen.
        $this->assertSame(0, $scanner->scan($transaction->fresh()));

        $this->assertSame(1, Issue::query()->count());
        $this->assertSame(1, PerformanceDetection::query()->count());
        $this->assertSame(1, Issue::query()->sole()->times_seen);
    }

    public function test_a_second_occurrence_raises_the_counters_of_the_same_issue(): void
    {
        [, , $project] = $this->context();

        $scanner = PerformanceScanner::fromConfig();

        $scanner->scan($this->traceWithNPlusOne($project));
        $scanner->scan($this->traceWithNPlusOne($project));

        $issue = Issue::query()->sole();

        $this->assertSame(2, $issue->times_seen);
        $this->assertSame(2 * 49 * 2_000, $issue->time_lost_us);
        $this->assertSame(2, PerformanceDetection::query()->count());
    }

    public function test_the_job_leaves_the_trace_marked_as_scanned(): void
    {
        [, , $project] = $this->context();

        $transaction = $this->traceWithNPlusOne($project);

        (new DetectPerformanceIssues($transaction))->handle();

        $this->assertNotNull($transaction->fresh()->scanned_at);
        $this->assertSame(1, Issue::query()->count());
    }

    /**
     * Die Zusage der Aufgabe: die Erkennung läuft nie in der Verarbeitung der
     * Meldung selbst, sondern auf dem bereits gespeicherten Ablauf.
     *
     * Gefälscht wird gezielt **nur** der Erkennungs-Auftrag — die Verarbeitung
     * läuft echt durch. Eine vollständige Fälschung der Warteschlange prüfte
     * sonst nur, dass ein Test nichts tut.
     */
    public function test_the_ingest_pipeline_only_queues_the_detection(): void
    {
        Queue::fake([DetectPerformanceIssues::class]);

        $key = Project::factory()->create()->keys()->firstOrFail();

        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body(TransactionPayload::make(), IngestType::Transaction)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        Queue::assertPushedOn(QueueName::Performance->value, DetectPerformanceIssues::class);

        // Und in der Verarbeitung selbst ist nichts erkannt worden.
        $this->assertSame(0, PerformanceDetection::query()->count());
        $this->assertNull(Transaction::query()->sole()->scanned_at);
    }

    public function test_performance_issues_stay_out_of_the_error_list(): void
    {
        [$user, , $project] = $this->context();

        PerformanceScanner::fromConfig()->scan($this->traceWithNPlusOne($project));

        // Ein echter Fehler daneben, damit der Test nicht schon deshalb besteht,
        // weil die Fehlerliste immer leer ist.
        Issue::factory()->for($project)->create([
            'category' => IssueCategory::Error,
            'title' => 'RuntimeException: Zahlung fehlgeschlagen',
            'first_seen' => Carbon::now()->subHour(),
            'last_seen' => Carbon::now()->subMinutes(5),
        ]);

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'RuntimeException: Zahlung fehlgeschlagen')
            );

        $this->actingAs($user)
            ->get(route('performance.issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('performance/Issues')
                ->has('issues.data', 1)
                ->where('issues.data.0.problem', PerformanceProblem::NPlusOneQueries->value)
                ->where('issues.data.0.timeLostUs', 49 * 2_000)
            );
    }

    public function test_the_detail_page_shows_an_example_with_its_spans(): void
    {
        [$user, , $project] = $this->context();

        PerformanceScanner::fromConfig()->scan($this->traceWithNPlusOne($project));

        $issue = Issue::query()->sole();

        $this->actingAs($user)
            ->get(route('performance.issues.show', $issue))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('performance/IssueDetail')
                ->has('examples', 1)
                ->where('examples.0.spanCount', 50)
                ->has('examples.0.spans', 50)
                ->where('examples.0.traceId', PerformanceDetection::query()->sole()->trace_id)
            );
    }

    public function test_an_error_issue_is_not_reachable_under_the_performance_detail_route(): void
    {
        [$user, , $project] = $this->context();

        $issue = Issue::factory()->for($project)->create([
            'category' => IssueCategory::Error,
            'first_seen' => Carbon::now()->subHour(),
            'last_seen' => Carbon::now(),
        ]);

        $this->actingAs($user)
            ->get(route('performance.issues.show', $issue))
            ->assertNotFound();
    }

    public function test_a_raised_threshold_keeps_the_pattern_out_of_the_list(): void
    {
        [, , $project] = $this->context();

        PerformanceSetting::query()->create([
            'project_id' => $project->id,
            'problem' => PerformanceProblem::NPlusOneQueries->value,
            'is_enabled' => true,
            // Mehr Wiederholungen, als der Ablauf hat.
            'thresholds' => ['min_count' => 100, 'min_total_ms' => 50],
        ]);

        PerformanceScanner::fromConfig()->scan($this->traceWithNPlusOne($project->fresh()));

        $this->assertSame(
            0,
            Issue::query()->where('type', PerformanceProblem::NPlusOneQueries->value)->count(),
        );
    }

    public function test_a_disabled_detector_is_not_run(): void
    {
        [, , $project] = $this->context();

        PerformanceSetting::query()->create([
            'project_id' => $project->id,
            'problem' => PerformanceProblem::NPlusOneQueries->value,
            'is_enabled' => false,
            'thresholds' => null,
        ]);

        PerformanceScanner::fromConfig()->scan($this->traceWithNPlusOne($project->fresh()));

        $this->assertSame(
            0,
            Issue::query()->where('type', PerformanceProblem::NPlusOneQueries->value)->count(),
        );
    }

    public function test_settings_store_only_what_differs_from_the_defaults(): void
    {
        [$user, $organization, $project] = $this->context();

        $payload = ['problems' => []];

        foreach (PerformanceProblem::cases() as $problem) {
            $payload['problems'][$problem->value] = [
                'enabled' => true,
                'thresholds' => $problem->defaults(),
            ];
        }

        // Genau eine Abweichung.
        $payload['problems'][PerformanceProblem::SlowHttpCall->value]['thresholds']['min_duration_ms'] = 250;

        $this->actingAs($user)
            ->patch(route('projects.performance.update', [$organization, $project]), $payload)
            ->assertRedirect();

        $setting = PerformanceSetting::query()->sole();

        $this->assertSame(PerformanceProblem::SlowHttpCall, $setting->problem);
        $this->assertSame(250, $setting->thresholds['min_duration_ms']);
    }

    public function test_settings_reject_a_threshold_below_its_lower_bound(): void
    {
        [$user, $organization, $project] = $this->context();

        $payload = ['problems' => []];

        foreach (PerformanceProblem::cases() as $problem) {
            $payload['problems'][$problem->value] = [
                'enabled' => true,
                'thresholds' => $problem->defaults(),
            ];
        }

        // Eine einzelne Abfrage als „wiederholt" — das darf die Prüfung nicht
        // durchlassen.
        $payload['problems'][PerformanceProblem::NPlusOneQueries->value]['thresholds']['min_count'] = 1;

        $this->actingAs($user)
            ->patch(route('projects.performance.update', [$organization, $project]), $payload)
            ->assertSessionHasErrors('problems.n_plus_one_queries.thresholds.min_count');

        $this->assertSame(0, PerformanceSetting::query()->count());
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Webshop', 'slug' => 'webshop']);

        $user->switchOrganization($organization);

        return [$user, $organization, $project];
    }

    /**
     * Ein Ablauf mit dem Lehrbuchfall: eine Abfrage holt die Bestellungen,
     * danach werden die Posten je Bestellung einzeln nachgeschlagen.
     *
     * Gebaut wird er über die Modelle und nicht über die Aufnahme: dass eine
     * gemeldete Transaktion samt Schritten in der Datenbank landet, ist die
     * Zusage von PF1 und hat dort ihren Test. Hier geht es um das, was danach
     * damit geschieht.
     */
    private function traceWithNPlusOne(Project $project): Transaction
    {
        $startedAt = Carbon::now()->subMinutes(5);

        $transaction = Transaction::factory()->for($project)->create([
            'name' => 'GET /bestellungen',
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMilliseconds(300),
            'duration_us' => 300_000,
            'user_identifier' => '4711',
        ]);

        $rows = [[
            'op' => 'db.sql.query',
            'description' => 'select * from orders where user_id = 7',
            'offset_us' => 0,
            'duration_us' => 10_000,
        ]];

        for ($i = 0; $i < 50; $i++) {
            $rows[] = [
                'op' => 'db.sql.query',
                'description' => 'select * from items where order_id = '.($i + 1),
                // Nacheinander, jede wartet auf die vorige.
                'offset_us' => 20_000 + $i * 4_000,
                'duration_us' => 2_000,
            ];
        }

        // Über `insert` und nicht über das Modell — wie die Aufnahme selbst
        // ({@see \App\Support\Performance\TransactionStore}). Ein Schritt hat
        // bewusst keine Massenzuweisung: er entsteht nur dort, aus geprüften
        // Werten.
        $format = 'Y-m-d H:i:s.v';
        $now = Carbon::now()->format($format);
        $spans = [];

        foreach ($rows as $position => $row) {
            $spanStartedAt = $startedAt->copy()->addMicroseconds($row['offset_us']);

            $spans[] = [
                'transaction_id' => $transaction->id,
                'project_id' => $project->id,
                'trace_id' => $transaction->trace_id,
                'span_id' => substr(md5($transaction->id.'-'.$position), 0, 16),
                'parent_span_id' => $transaction->span_id,
                'op' => $row['op'],
                'description' => $row['description'],
                'status' => 'ok',
                'started_at' => $spanStartedAt->format($format),
                'finished_at' => $spanStartedAt->copy()->addMicroseconds($row['duration_us'])->format($format),
                'duration_us' => $row['duration_us'],
                'data' => null,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TransactionSpan::query()->insert($spans);

        return $transaction->fresh();
    }
}
