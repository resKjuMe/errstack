<?php

namespace App\Support;

use App\Enums\CronIntervalUnit;
use App\Enums\CronScheduleType;
use App\Models\CronCheckIn;
use App\Models\CronMonitor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Cronjob-Seite eines Projekts.
 *
 * Die Seite beantwortet zwei Fragen, und in dieser Reihenfolge: „ist gerade
 * etwas kaputt?" und „wie lief es zuletzt?". Deshalb steht der Zustand vorn und
 * der Verlauf darunter — und deshalb ist der Verlauf auf die letzten
 * Ausführungen begrenzt: wer weiter zurückblättern will, sucht etwas anderes
 * als den Betriebszustand.
 */
final class CronMonitorData
{
    /**
     * @return array<string, mixed>
     */
    public static function index(Project $project, User $viewer): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $mayManage = Gate::forUser($viewer)->allows('manageCrons', $project);

        // Die Check-in-Adresse enthält den öffentlichen Schlüssel. Sie steht
        // deshalb unter derselben Bedingung wie die DSN auf der
        // Schlüssel-Seite — wer die Schlüssel nicht verwalten darf, soll sie
        // auch hier nicht ablesen können.
        $maySeeKeys = Gate::forUser($viewer)->allows('manageKeys', $project);

        $monitors = $project->cronMonitors()
            ->with(['checkIns' => fn ($query) => $query->latest('id')->limit(CronMonitor::HISTORY_LIMIT)])
            ->orderBy('name')
            ->get();

        return [
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'cronsHref' => route('projects.crons.index', [$organization, $project]),
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
                ->map(fn (CronMonitor $monitor): array => self::monitor($organization, $project, $monitor, $maySeeKeys))
                ->all(),
            'scheduleTypeOptions' => CronScheduleType::options(),
            'intervalUnitOptions' => CronIntervalUnit::options(),
            'timezones' => self::timezones(),
            // Die Vorgaben des Anlege-Formulars kommen von hier und nicht aus
            // der Oberfläche: sie müssen zu den Vorgaben der Tabelle passen,
            // und zwei Stellen mit denselben Zahlen laufen auseinander.
            'defaults' => self::defaults(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function monitor(Organization $organization, Project $project, CronMonitor $monitor, bool $maySeeKeys): array
    {
        $parameters = [$organization, $project, $monitor];
        $status = $monitor->displayStatus();

        return [
            'id' => $monitor->id,
            'slug' => $monitor->slug,
            'name' => $monitor->name,

            'scheduleType' => $monitor->schedule_type->value,
            'scheduleExpression' => $monitor->schedule_expression,
            'intervalValue' => $monitor->interval_value,
            'intervalUnit' => $monitor->interval_unit?->value,
            'timezone' => $monitor->timezone,
            'scheduleLabel' => $monitor->schedule()?->describe(),

            'checkinMarginMinutes' => $monitor->checkin_margin_minutes,
            'maxRuntimeMinutes' => $monitor->max_runtime_minutes,
            'failureTolerance' => $monitor->failure_tolerance,
            'recoveryTolerance' => $monitor->recovery_tolerance,

            'isActive' => $monitor->is_active,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'isFailing' => $status->isFailing(),
            'consecutiveFailures' => $monitor->consecutive_failures,

            'lastCheckInAt' => $monitor->last_check_in_at?->toIso8601String(),
            'lastCheckInLabel' => Formats::dateTime($monitor->last_check_in_at),
            'nextDueAt' => $monitor->next_due_at?->toIso8601String(),
            'nextDueLabel' => Formats::dateTime($monitor->next_due_at),

            // Die Adresse zum Einbauen in den Job — nur für die, die auch die
            // DSN sehen dürfen (siehe oben).
            'checkInUrl' => $maySeeKeys ? self::checkInUrl($project, $monitor) : null,

            'history' => $monitor->checkIns
                ->map(fn (CronCheckIn $checkIn): array => self::checkIn($checkIn))
                ->all(),

            'href' => route('projects.crons.update', $parameters),
            'toggleHref' => route('projects.crons.toggle', $parameters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkIn(CronCheckIn $checkIn): array
    {
        return [
            'id' => $checkIn->id,
            'status' => $checkIn->status->value,
            'statusLabel' => $checkIn->status->label(),
            'isFailure' => $checkIn->status->isFailure(),
            'environment' => $checkIn->environment,
            'expectedLabel' => Formats::dateTime($checkIn->expected_at),
            'startedLabel' => Formats::dateTime($checkIn->started_at),
            'finishedLabel' => Formats::dateTime($checkIn->finished_at),
            'durationLabel' => $checkIn->duration_ms === null
                ? null
                : Formats::duration($checkIn->duration_ms),
            'delaySeconds' => $checkIn->delaySeconds(),
        ];
    }

    /**
     * Die Check-in-Adresse mit dem ersten aktiven Schlüssel des Projekts.
     *
     * Der erste genügt: welcher Schlüssel ein Lebenszeichen einliefert, ist
     * gleichgültig — er weist nur das Projekt aus. `null`, wenn das Projekt
     * gerade keinen aktiven Schlüssel hat; dann ist die Adresse ohnehin
     * nutzlos.
     */
    private static function checkInUrl(Project $project, CronMonitor $monitor): ?string
    {
        $key = $project->keys()->where('active', true)->orderBy('id')->first();

        return $key === null ? null : route('ingest.cron', [
            'project' => $project->id,
            'monitor' => $monitor->slug,
            'key' => $key->public_key,
        ]);
    }

    /**
     * Vorbelegung des Anlege-Formulars.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'scheduleType' => CronScheduleType::Crontab->value,
            'scheduleExpression' => '0 2 * * *',
            'intervalValue' => 1,
            'intervalUnit' => CronIntervalUnit::Hour->value,
            'timezone' => config('app.timezone', 'UTC'),
            'checkinMarginMinutes' => 5,
            'maxRuntimeMinutes' => 30,
            'failureTolerance' => 1,
            'recoveryTolerance' => 1,
        ];
    }

    /**
     * Die Zeitzonen für das Auswahlfeld. Die vollständige Liste — eine
     * gekürzte Auswahl trifft immer den falschen Job.
     *
     * @return list<string>
     */
    private static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
}
