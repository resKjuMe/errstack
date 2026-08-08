<?php

namespace App\Http\Controllers;

use App\Console\Commands\IngestRetryCommand;
use App\Enums\ProcessingState;
use App\Http\Requests\RetryFailedJobRequest;
use App\Providers\AppServiceProvider;
use App\Support\Formats;
use App\Support\Ingest\Processing\ProcessingMetrics;
use App\Support\Operations\BacklogWatch;
use App\Support\Operations\FailedJobs;
use App\Support\Operations\HealthCheck;
use App\Support\Operations\IngestRetry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Betriebsansicht: läuft diese Installation noch rund?
 *
 * Sie beantwortet die drei Fragen, die im Ernstfall in dieser Reihenfolge
 * gestellt werden — steht noch alles (Zustandsprüfung), kommt die Verarbeitung
 * mit (Rückstand und Dauern), und was ist liegengeblieben (gescheiterte Jobs
 * und Meldungen). Die dritte ist die einzige mit Schaltflächen: nachsehen
 * genügt nicht, wenn man das Liegengebliebene nicht auch wieder in Gang setzen
 * kann.
 *
 * Zugang über das Gate `operations` ({@see AppServiceProvider}) —
 * das ist eine Frage an die Installation und nicht an eine Organisation.
 */
class OperationsController extends Controller
{
    /** Wie viele gescheiterte Jobs die Ansicht zeigt. */
    private const FAILED_JOBS_SHOWN = 50;

    public function index(
        ProcessingMetrics $metrics,
        BacklogWatch $backlog,
        FailedJobs $failedJobs,
        HealthCheck $health,
    ): InertiaResponse {
        Gate::authorize('operations');

        $checks = $health->run();
        $status = $backlog->status();
        $states = $metrics->states();

        return Inertia::render('operations/Index', [
            'health' => [
                'overall' => HealthCheck::overall($checks)->value,
                'checks' => array_map(static fn (array $check, string $name): array => [
                    'name' => $name,
                    'label' => __('operations.health.checks.'.$name),
                    'state' => $check['state']->value,
                    'durationMs' => $check['duration_ms'],
                ], $checks, array_keys($checks)),
            ],
            'backlog' => [
                'pending' => $status['pending'],
                'oldestSeconds' => $status['oldest_seconds'],
                'breaching' => $status['breaching'],
                'reasons' => $status['reasons'],
                'since' => Formats::dateTime($status['since']),
                'maxPending' => $status['max_pending'],
                'maxAgeSeconds' => $status['max_age_seconds'],
            ],
            'durations' => $metrics->durations(),
            'latency' => $metrics->latency(),
            'queues' => array_map(static fn (?int $size, string $queue): array => [
                'name' => $queue,
                'size' => $size,
            ], $metrics->queueSizes(), array_keys($metrics->queueSizes())),
            'states' => array_map(static fn (int $total, string $state): array => [
                'state' => $state,
                'label' => ProcessingState::from($state)->label(),
                'total' => $total,
            ], $states, array_keys($states)),
            'failedJobs' => [
                'total' => $failedJobs->count(),
                'shown' => self::FAILED_JOBS_SHOWN,
                'entries' => array_map(static fn (array $job): array => [
                    ...$job,
                    'failedAt' => Formats::dateTimeSeconds(
                        $job['failedAt'] === null ? null : Carbon::parse($job['failedAt']),
                    ),
                ], $failedJobs->recent(self::FAILED_JOBS_SHOWN)),
            ],
            'failedPayloads' => $states[ProcessingState::Failed->value] ?? 0,
            'retryLimit' => IngestRetry::DEFAULT_LIMIT,
            // Die Adressen kommen vom Server, wie überall in dieser Oberfläche:
            // die Routen tragen deutsche Pfade, und ein zweites Mal
            // abgeschrieben wären sie beim ersten Umbenennen falsch.
            'actions' => [
                'retryJob' => route('operations.jobs.retry'),
                'retryAllJobs' => route('operations.jobs.retry-all'),
                'forgetJob' => route('operations.jobs.forget'),
                'retryPayloads' => route('operations.payloads.retry'),
            ],
        ]);
    }

    /**
     * Startet einen einzelnen gescheiterten Job erneut.
     */
    public function retryJob(RetryFailedJobRequest $request, FailedJobs $failedJobs): RedirectResponse
    {
        Gate::authorize('operations');

        $found = $failedJobs->retry($request->jobId());

        return back()->with('status', $found
            ? __('operations.failed_jobs.retried_one')
            : __('operations.failed_jobs.gone'));
    }

    /**
     * Startet alle gescheiterten Jobs erneut.
     */
    public function retryAllJobs(FailedJobs $failedJobs): RedirectResponse
    {
        Gate::authorize('operations');

        $count = $failedJobs->retryAll();

        return back()->with('status', __('operations.failed_jobs.retried_all', ['count' => $count]));
    }

    /**
     * Wirft einen gescheiterten Job weg.
     */
    public function forgetJob(RetryFailedJobRequest $request, FailedJobs $failedJobs): RedirectResponse
    {
        Gate::authorize('operations');

        $found = $failedJobs->forget($request->jobId());

        return back()->with('status', $found
            ? __('operations.failed_jobs.forgotten')
            : __('operations.failed_jobs.gone'));
    }

    /**
     * Reiht die gescheiterten **Meldungen** erneut ein.
     *
     * Die Gegenstelle zum Wiederholen eines Jobs, und aus demselben Grund
     * getrennt wie an der Kommandozeile ({@see IngestRetryCommand}):
     * nach einem reparierten Schritt gibt es keinen Job mehr, den man
     * wiederholen könnte, die Rohdaten liegen aber noch da.
     */
    public function retryPayloads(IngestRetry $retry): RedirectResponse
    {
        Gate::authorize('operations');

        $count = $retry->queueFailed();

        return back()->with('status', __('operations.failed_payloads.retried', ['count' => $count]));
    }
}
