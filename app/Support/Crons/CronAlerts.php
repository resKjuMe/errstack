<?php

namespace App\Support\Crons;

use App\Enums\CronCheckInStatus;
use App\Enums\NotificationLevel;
use App\Models\CronCheckIn;
use App\Models\CronMonitor;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Formats;

/**
 * Meldet, wenn ein überwachter Job ausfällt — und wenn er sich wieder fängt.
 *
 * Die Entscheidung **ob** gemeldet wird, fällt am Monitor (Fehlertoleranz);
 * hier steht nur, **was** dann rausgeht. Der Weg dorthin ist derselbe wie bei
 * jedem anderen Alarm: {@see NotificationDispatcher} und die Kanäle der
 * Organisation aus A1 — ein eigener Versandweg für Cronjobs wäre eine zweite
 * Stelle, an der jemand seine Ruhezeiten einstellen müsste.
 *
 * Die Entwarnung ist nicht Beiwerk. Wer nur den Ausfall meldet, zwingt jeden
 * Empfänger dazu, selbst nachzusehen, ob es sich erledigt hat — und genau das
 * unterbleibt dann.
 */
final class CronAlerts
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Ein Job ist ausgefallen: verpasst, zu lange gelaufen oder gescheitert.
     */
    public function fired(CronMonitor $monitor, CronCheckIn $checkIn): void
    {
        $this->dispatcher->send($monitor->project->organization, new NotificationMessage(
            title: __('crons.alert.title', [
                'monitor' => $monitor->name,
                'reason' => $checkIn->status->label(),
            ]),
            body: $this->body($monitor, $checkIn),
            // Ein verpasster Job ist ein Ausfall, kein Hinweis — auch dann,
            // wenn dabei nichts abgestürzt ist.
            level: NotificationLevel::Error,
            url: $this->url($monitor),
            context: $this->context($monitor, $checkIn),
            reference: 'CRON-'.$monitor->slug,
            occurredAt: $checkIn->finished_at ?? $checkIn->expected_at ?? now(),
        ));
    }

    /**
     * Der Job läuft wieder.
     */
    public function recovered(CronMonitor $monitor, CronCheckIn $checkIn): void
    {
        $this->dispatcher->send($monitor->project->organization, new NotificationMessage(
            title: __('crons.recovery.title', ['monitor' => $monitor->name]),
            body: __('crons.recovery.body', [
                'monitor' => $monitor->name,
                'project' => $monitor->project->name,
            ]),
            level: NotificationLevel::Info,
            url: $this->url($monitor),
            context: $this->context($monitor, $checkIn),
            reference: 'CRON-'.$monitor->slug,
            occurredAt: $checkIn->finished_at ?? now(),
        ));
    }

    /**
     * Der Text sagt, was passiert ist — und je nach Fall auch, was erwartet
     * worden wäre. „Der Job hat sich nicht gemeldet" allein hilft niemandem,
     * der wissen will, ob er den letzten Lauf noch nachholen kann.
     */
    private function body(CronMonitor $monitor, CronCheckIn $checkIn): string
    {
        $replace = [
            'monitor' => $monitor->name,
            'project' => $monitor->project->name,
            'expected' => $checkIn->expected_at === null
                ? __('crons.alert.unknown_time')
                : Formats::dateTime($checkIn->expected_at),
        ];

        return match ($checkIn->status) {
            CronCheckInStatus::Missed => __('crons.alert.body_missed', $replace),
            CronCheckInStatus::Timeout => __('crons.alert.body_timeout', $replace + [
                'runtime' => (string) $monitor->max_runtime_minutes,
            ]),
            default => __('crons.alert.body_error', $replace),
        };
    }

    /**
     * @return array<string, string>
     */
    private function context(CronMonitor $monitor, CronCheckIn $checkIn): array
    {
        $context = [
            __('crons.alert.context_project') => $monitor->project->name,
            __('crons.alert.context_monitor') => $monitor->slug,
            __('crons.alert.context_schedule') => $monitor->schedule()?->describe()
                ?? __('crons.alert.unknown_schedule'),
        ];

        if ($checkIn->environment !== null) {
            $context[__('crons.alert.context_environment')] = $checkIn->environment;
        }

        if ($checkIn->duration_ms !== null) {
            $context[__('crons.alert.context_duration')] = Formats::duration($checkIn->duration_ms);
        }

        return $context;
    }

    /**
     * Der Link führt auf die Übersicht des Projekts, nicht auf den einzelnen
     * Lauf: wer eine solche Meldung liest, will als Erstes wissen, ob nur
     * dieser eine Job betroffen ist.
     */
    private function url(CronMonitor $monitor): string
    {
        return route('projects.crons.index', [
            $monitor->project->organization,
            $monitor->project,
        ]);
    }
}
