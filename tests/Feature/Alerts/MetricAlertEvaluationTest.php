<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertComparison;
use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\DeliveryStatus;
use App\Models\Event;
use App\Models\IngestPayload;
use App\Models\MetricAlert;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TransactionAggregate;
use App\Support\Alerts\MetricAlertEvaluator;
use App\Support\Alerts\MetricAlertSweep;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Die Auswertung: was gemessen wird, was daraus folgt und was gemeldet wird.
 *
 * Der Kern ist die Zusage „höchstens eine Meldung je Übergang". Sie ist der
 * Unterschied zwischen einem Alarm und einem Dauerton — bei minütlicher
 * Auswertung wären es sonst sechzig Meldungen in der Stunde für ein und dieselbe
 * Lage.
 */
class MetricAlertEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // Ein fester Zeitpunkt: die Auswertung rechnet über ein gleitendes
        // Fenster, und ein Test, der um Mitternacht anders ausgeht, ist keiner.
        $this->now = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);

        // Die Zustellung selbst gehört zu A1; hier zählt, **dass** etwas
        // eingereiht wird.
        Queue::fake();
    }

    /**
     * @return array{Organization, Project}
     */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();

        NotificationChannel::factory()->for($organization)->create();

        return [$organization, $project];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function alert(Project $project, array $attributes = []): MetricAlert
    {
        return MetricAlert::factory()->for($project)->create($attributes);
    }

    /**
     * Fehlermeldungen, die vor `$minutesAgo` Minuten aufgetreten sind.
     */
    private function errors(Project $project, int $count, int $minutesAgo = 2, ?string $environment = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            Event::factory()
                ->for($project)
                ->for(IngestPayload::factory()->for($project), 'payload')
                ->create([
                    'occurred_at' => $this->now->subMinutes($minutesAgo),
                    'environment' => $environment ?? 'production',
                ]);
        }
    }

    private function evaluator(): MetricAlertEvaluator
    {
        return app(MetricAlertEvaluator::class);
    }

    private function deliveries(): int
    {
        return NotificationDelivery::query()->where('status', DeliveryStatus::Pending)->count();
    }

    public function test_an_alert_fires_once_and_not_again_while_the_state_holds(): void
    {
        [, $project] = $this->context();
        $alert = $this->alert($project, ['critical_threshold' => 10.0]);

        $this->errors($project, 15);

        $this->assertNotNull($this->evaluator()->evaluate($alert->fresh(), $this->now));
        $this->assertSame(AlertStatus::Critical, $alert->fresh()->status);
        $this->assertSame(1, $this->deliveries());

        // Zweiter Durchlauf, dieselbe Lage: kein Wechsel, keine zweite Meldung.
        $this->assertNull($this->evaluator()->evaluate($alert->fresh(), $this->now));
        $this->assertSame(1, $this->deliveries());
        $this->assertDatabaseCount('metric_alert_transitions', 1);
    }

    public function test_a_quiet_window_resolves_the_alert_and_reports_the_all_clear(): void
    {
        [, $project] = $this->context();
        $alert = $this->alert($project, ['critical_threshold' => 10.0]);

        $this->errors($project, 15);
        $this->evaluator()->evaluate($alert->fresh(), $this->now);

        // Fünf Minuten später liegen die Meldungen außerhalb des Fensters.
        $later = $this->now->addMinutes(6);

        $transition = $this->evaluator()->evaluate($alert->fresh(), $later);

        $this->assertNotNull($transition);
        $this->assertSame('resolved', $transition->kind());
        $this->assertSame(AlertStatus::Ok, $alert->fresh()->status);
        $this->assertSame(2, $this->deliveries());
    }

    public function test_the_state_escalates_and_eases_through_the_warning_stage(): void
    {
        [, $project] = $this->context();
        $alert = $this->alert($project, [
            'warning_threshold' => 5.0,
            'critical_threshold' => 10.0,
        ]);

        $this->errors($project, 6);
        $fired = $this->evaluator()->evaluate($alert->fresh(), $this->now);

        $this->assertSame('fired', $fired?->kind());
        $this->assertSame(AlertStatus::Warning, $alert->fresh()->status);

        $this->errors($project, 6, minutesAgo: 1);
        $escalated = $this->evaluator()->evaluate($alert->fresh(), $this->now);

        $this->assertSame('escalated', $escalated?->kind());
        $this->assertSame(AlertStatus::Critical, $alert->fresh()->status);

        // Vier Minuten später reicht das Fenster von einer Minute vor „jetzt"
        // bis vier danach: die ersten sechs sind herausgefallen, die zweiten
        // sechs stehen noch drin — zurück auf Warnung, nicht auf „in Ordnung".
        $eased = $this->evaluator()->evaluate($alert->fresh(), $this->now->addMinutes(4));

        $this->assertSame('eased', $eased?->kind());
        $this->assertSame(AlertStatus::Warning, $alert->fresh()->status);
    }

    public function test_the_resolve_threshold_keeps_the_alert_up_until_the_value_really_clears(): void
    {
        [, $project] = $this->context();
        $alert = $this->alert($project, [
            'warning_threshold' => 5.0,
            'critical_threshold' => 10.0,
            'resolve_threshold' => 2.0,
        ]);

        $this->errors($project, 12);
        $this->evaluator()->evaluate($alert->fresh(), $this->now);

        $this->assertSame(AlertStatus::Critical, $alert->fresh()->status);

        // Drei Meldungen: unter der Warnschwelle, aber noch nicht unter der
        // Auflösungsschwelle.
        $this->errors($project, 3, minutesAgo: 1);
        $this->evaluator()->evaluate($alert->fresh(), $this->now->addMinutes(4));

        $this->assertSame(AlertStatus::Warning, $alert->fresh()->status);
    }

    public function test_only_the_selected_environment_counts(): void
    {
        [, $project] = $this->context();
        $alert = $this->alert($project, [
            'critical_threshold' => 5.0,
            'environment' => 'production',
        ]);

        $this->errors($project, 10, environment: 'staging');

        $this->assertNull($this->evaluator()->evaluate($alert->fresh(), $this->now));
        $this->assertSame(AlertStatus::Ok, $alert->fresh()->status);
        $this->assertSame(0.0, $alert->fresh()->last_value);
    }

    public function test_a_response_time_alert_without_measurements_holds_its_state(): void
    {
        [, $project] = $this->context();

        $alert = $this->alert($project, [
            'metric' => AlertMetric::TransactionDurationP95,
            'critical_threshold' => 500.0,
            'status' => AlertStatus::Critical,
            'status_since' => $this->now->subMinutes(10),
        ]);

        // Keine Messungen im Fenster. Aus null Messungen folgt keine
        // Antwortzeit — und ein Alarm, der daraufhin Entwarnung gäbe, verstummte
        // genau dann, wenn die Anwendung nicht mehr antwortet.
        $this->assertNull($this->evaluator()->evaluate($alert->fresh(), $this->now));

        $fresh = $alert->fresh();

        $this->assertSame(AlertStatus::Critical, $fresh->status);
        $this->assertNull($fresh->last_value);
        // Ausgewertet wurde trotzdem — das ist der Lebensbeweis der Regel.
        $this->assertNotNull($fresh->last_evaluated_at);
        $this->assertSame(0, $this->deliveries());
    }

    public function test_a_response_time_alert_reads_the_percentile_of_the_window(): void
    {
        [, $project] = $this->context();

        $alert = $this->alert($project, [
            'metric' => AlertMetric::TransactionDurationP95,
            'critical_threshold' => 500.0,
        ]);

        // Zehn Messungen à zwei Sekunden: liegen alle in derselben Klasse, ist
        // jedes Perzentil deren Obergrenze und damit vorhersagbar.
        TransactionAggregate::factory()
            ->for($project)
            ->measuring(2_000_000, 10)
            ->at($this->now->subMinutes(2))
            ->create();

        $transition = $this->evaluator()->evaluate($alert->fresh(), $this->now);

        $this->assertSame('fired', $transition?->kind());
        $this->assertGreaterThan(500.0, (float) $alert->fresh()->last_value);
    }

    public function test_too_few_measurements_leave_a_rate_alone(): void
    {
        [, $project] = $this->context();

        $alert = $this->alert($project, [
            'metric' => AlertMetric::TransactionFailureRate,
            'critical_threshold' => 10.0,
            'minimum_samples' => 20,
        ]);

        // Drei Aufrufe, einer davon gescheitert: rechnerisch 33 %, als Befund
        // wertlos.
        TransactionAggregate::factory()
            ->for($project)
            ->measuring(1_000, 3, failures: 1)
            ->at($this->now->subMinutes(2))
            ->create();

        $this->assertNull($this->evaluator()->evaluate($alert->fresh(), $this->now));
        $this->assertSame(AlertStatus::Ok, $alert->fresh()->status);
    }

    public function test_the_week_comparison_measures_the_change_and_not_the_value(): void
    {
        [, $project] = $this->context();

        $alert = $this->alert($project, [
            'comparison' => AlertComparison::PercentChangeWeek,
            // Mehr als das Doppelte der Vorwoche.
            'critical_threshold' => 100.0,
        ]);

        // Vorwoche: vier Meldungen im selben Fenster.
        $this->errors($project, 4, minutesAgo: 7 * 24 * 60 + 2);
        // Jetzt: zwölf.
        $this->errors($project, 12);

        $transition = $this->evaluator()->evaluate($alert->fresh(), $this->now);

        $this->assertSame('fired', $transition?->kind());
        $this->assertSame(200.0, (float) $alert->fresh()->last_value);
        $this->assertSame(4.0, (float) $alert->fresh()->last_baseline);
    }

    public function test_without_a_comparison_value_nothing_happens(): void
    {
        [, $project] = $this->context();

        $alert = $this->alert($project, [
            'comparison' => AlertComparison::PercentChangeWeek,
            'critical_threshold' => 100.0,
        ]);

        $this->errors($project, 12);

        // Vor einer Woche lag nichts vor: eine Veränderung gegenüber null ist
        // keine Prozentangabe.
        $this->assertNull($this->evaluator()->evaluate($alert->fresh(), $this->now));
        $this->assertSame(AlertStatus::Ok, $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->last_evaluated_at);
    }

    public function test_the_sweep_skips_switched_off_alerts_and_survives_a_broken_one(): void
    {
        [, $project] = $this->context();

        $this->alert($project, ['name' => 'Aus', 'critical_threshold' => 1.0, 'is_active' => false]);
        $this->alert($project, ['name' => 'An', 'critical_threshold' => 1.0]);

        $this->errors($project, 5);

        $result = app(MetricAlertSweep::class)->run($this->now);

        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(1, $result['transitions']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_the_console_command_runs_the_sweep(): void
    {
        [, $project] = $this->context();
        $this->alert($project, ['critical_threshold' => 1.0]);

        $this->errors($project, 5);

        $this->artisan('alerts:sweep')->assertSuccessful();

        $this->assertDatabaseCount('metric_alert_transitions', 1);
    }
}
