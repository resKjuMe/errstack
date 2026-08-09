<?php

namespace App\Support;

use App\Enums\HttpMethod;
use App\Models\Organization;
use App\Models\Project;
use App\Models\UptimeMonitor;
use App\Models\UptimeOutage;
use App\Models\User;
use App\Support\Uptime\UptimeStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Erreichbarkeits-Seite eines Projekts.
 *
 * Die Seite beantwortet drei Fragen, und in dieser Reihenfolge: „ist es gerade
 * da?", „wie zuverlässig war es?" und „wann war es weg?". Deshalb steht der
 * Zustand oben, die Quote daneben und die Liste der Ausfälle darunter — und
 * deshalb ist der Antwortzeit-Verlauf eine Kurve und keine Tabelle: gefragt ist
 * die Form, nicht der einzelne Messwert.
 */
final class UptimeMonitorData
{
    /**
     * @return array<string, mixed>
     */
    public static function index(Project $project, User $viewer): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $mayManage = Gate::forUser($viewer)->allows('manageUptime', $project);

        $monitors = $project->uptimeMonitors()
            ->with(['outages' => fn ($query) => $query->latest('started_at')->limit(UptimeMonitor::OUTAGE_LIMIT)])
            ->orderBy('name')
            ->get();

        $stats = new UptimeStats;
        $now = Carbon::now();

        return [
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'uptimeHref' => route('projects.uptime.index', [$organization, $project]),
            ],
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'permissions' => [
                'manage' => $mayManage,
            ],
            'monitors' => $monitors
                ->map(fn (UptimeMonitor $monitor): array => self::monitor($organization, $project, $monitor, $stats, $now))
                ->all(),
            'methodOptions' => HttpMethod::options(),
            // Die Vorgaben des Anlege-Formulars kommen von hier und nicht aus
            // der Oberfläche: sie müssen zu den Vorgaben der Tabelle passen, und
            // zwei Stellen mit denselben Zahlen laufen auseinander.
            'defaults' => self::defaults(),
            'minimumIntervalSeconds' => UptimeMonitor::MINIMUM_INTERVAL_SECONDS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function monitor(
        Organization $organization,
        Project $project,
        UptimeMonitor $monitor,
        UptimeStats $stats,
        Carbon $now,
    ): array {
        $parameters = [$organization, $project, $monitor];
        $status = $monitor->displayStatus();
        $availability = $stats->availability($monitor, $now);

        return [
            'id' => $monitor->id,
            'slug' => $monitor->slug,
            'name' => $monitor->name,
            'url' => $monitor->url,
            'method' => $monitor->method->value,

            'headers' => $monitor->headers ?? [],
            'body' => $monitor->body,
            'expectedStatusCodes' => $monitor->expected_status_codes,
            'expectedContent' => $monitor->expected_content,

            'intervalSeconds' => $monitor->interval_seconds,
            'timeoutSeconds' => $monitor->timeout_seconds,
            'confirmationRetries' => $monitor->confirmation_retries,
            'confirmationDelaySeconds' => $monitor->confirmation_delay_seconds,
            'failureThreshold' => $monitor->failure_threshold,
            'recoveryThreshold' => $monitor->recovery_threshold,
            'followRedirects' => $monitor->follow_redirects,
            'verifyTls' => $monitor->verify_tls,

            'isActive' => $monitor->is_active,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'isFailing' => $status->isFailing(),
            'consecutiveFailures' => $monitor->consecutive_failures,

            'lastCheckedAt' => $monitor->last_checked_at?->toIso8601String(),
            'lastCheckedLabel' => Formats::dateTimeSeconds($monitor->last_checked_at),
            'nextCheckAt' => $monitor->next_check_at?->toIso8601String(),
            'nextCheckLabel' => Formats::dateTimeSeconds($monitor->next_check_at),

            'availability' => $availability,
            // Die mittlere Antwortzeit über dasselbe Fenster wie die erste
            // Quote: zwei Zahlen mit verschiedenen Zeiträumen nebeneinander
            // liest niemand richtig.
            'averageResponseMs' => $stats->averageResponseTime($monitor, $now->copy()->subHours(UptimeStats::WINDOWS['day'])),
            'responseTimes' => $stats->responseTimes($monitor),

            'outages' => $monitor->outages
                ->map(fn (UptimeOutage $outage): array => self::outage($organization, $outage, $now))
                ->all(),

            'href' => route('projects.uptime.update', $parameters),
            'toggleHref' => route('projects.uptime.toggle', $parameters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function outage(Organization $organization, UptimeOutage $outage, Carbon $now): array
    {
        return [
            'id' => $outage->id,
            'reason' => $outage->outcome->value,
            'reasonLabel' => $outage->outcome->label(),
            'httpStatus' => $outage->http_status,
            'error' => $outage->error,
            'startedAt' => $outage->started_at->toIso8601String(),
            'startedLabel' => Formats::dateTimeSeconds($outage->started_at),
            'endedAt' => $outage->ended_at?->toIso8601String(),
            'endedLabel' => Formats::dateTimeSeconds($outage->ended_at),
            'durationLabel' => Formats::duration($outage->duration($now) * 1000),
            'isRunning' => $outage->isRunning(),
            'failedChecks' => $outage->failed_checks,
            // Der Weg vom Vorfall zum Fehler-Eintrag. `null`, wenn keiner
            // entstanden ist oder ihn jemand gelöscht hat — dass die Seite weg
            // war, bleibt trotzdem wahr.
            'issueHref' => $outage->issue_id === null
                ? null
                : route('issues.show', [$organization, $outage->issue_id]),
        ];
    }

    /**
     * Vorbelegung des Anlege-Formulars.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'method' => HttpMethod::Get->value,
            'expectedStatusCodes' => '200-299',
            'expectedContent' => '',
            'intervalSeconds' => 300,
            'timeoutSeconds' => 10,
            'confirmationRetries' => 1,
            'confirmationDelaySeconds' => 5,
            'failureThreshold' => 1,
            'recoveryThreshold' => 1,
            'followRedirects' => true,
            'verifyTls' => true,
        ];
    }
}
