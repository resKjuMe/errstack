<?php

namespace App\Support\Uptime;

use App\Enums\NotificationLevel;
use App\Models\UptimeMonitor;
use App\Models\UptimeOutage;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Formats;

/**
 * Meldet, wenn ein Ziel ausfällt — und wenn es wieder da ist.
 *
 * Die Entscheidung **ob** gemeldet wird, fällt am Monitor (Bestätigung und
 * Schwelle); hier steht nur, **was** dann rausgeht. Der Weg dorthin ist
 * derselbe wie bei jedem anderen Alarm: {@see NotificationDispatcher} und die
 * Kanäle der Organisation aus A1 — ein eigener Versandweg für Erreichbarkeit
 * wäre eine zweite Stelle, an der jemand seine Ruhezeiten einstellen müsste.
 *
 * **Der Ausfall ist dringend.** Anders als die meisten Meldungen wird er nicht
 * gebündelt: eine Sammelnachricht heute Abend über eine Seite, die seit heute
 * Vormittag weg ist, wäre kein Alarm, sondern ein Protokoll. Die Entwarnung
 * darf dagegen warten — sie nimmt niemandem etwas weg.
 */
final class UptimeAlerts
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Das Ziel ist ausgefallen.
     */
    public function down(UptimeMonitor $monitor, UptimeOutage $outage): void
    {
        $this->dispatcher->send($monitor->project->organization, new NotificationMessage(
            title: __('uptime.alert.title', ['monitor' => $monitor->name]),
            body: __('uptime.alert.body', [
                'monitor' => $monitor->name,
                'project' => $monitor->project->name,
                'url' => $monitor->url,
                'reason' => $outage->outcome->label(),
            ]),
            level: NotificationLevel::Error,
            url: $this->url($monitor),
            context: $this->context($monitor, $outage),
            // Dieselbe Kennung für Ausfall und Entwarnung: die beiden gehören
            // zusammen, und ein Kanal, der Meldungen zu einem Vorgang bündelt,
            // soll sie zusammenlegen können.
            reference: 'UPTIME-'.$monitor->slug,
            occurredAt: $outage->started_at,
            urgent: true,
        ));
    }

    /**
     * Das Ziel ist wieder erreichbar.
     *
     * Die Entwarnung ist nicht Beiwerk. Wer nur den Ausfall meldet, zwingt
     * jeden Empfänger dazu, selbst nachzusehen, ob es sich erledigt hat — und
     * genau das unterbleibt dann. Die Dauer steht darin, weil sie die erste
     * Frage danach beantwortet.
     */
    public function recovered(UptimeMonitor $monitor, UptimeOutage $outage): void
    {
        $this->dispatcher->send($monitor->project->organization, new NotificationMessage(
            title: __('uptime.recovery.title', ['monitor' => $monitor->name]),
            body: __('uptime.recovery.body', [
                'monitor' => $monitor->name,
                'project' => $monitor->project->name,
                'duration' => self::duration($outage),
            ]),
            level: NotificationLevel::Info,
            url: $this->url($monitor),
            context: $this->context($monitor, $outage),
            reference: 'UPTIME-'.$monitor->slug,
            occurredAt: $outage->ended_at,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function context(UptimeMonitor $monitor, UptimeOutage $outage): array
    {
        $context = [
            __('uptime.alert.context_project') => $monitor->project->name,
            __('uptime.alert.context_url') => $monitor->url,
            __('uptime.alert.context_reason') => $outage->outcome->label(),
            __('uptime.alert.context_started') => (string) Formats::dateTimeSeconds($outage->started_at),
        ];

        if ($outage->http_status !== null) {
            $context[__('uptime.alert.context_status')] = (string) $outage->http_status;
        }

        // Der Fehlertext der Gegenstelle steht nur im Ausfall, nicht in der
        // Entwarnung — dort wäre er die Antwort auf eine Frage, die sich
        // erledigt hat.
        if ($outage->isRunning() && $outage->error !== null) {
            $context[__('uptime.alert.context_error')] = $outage->error;
        }

        if (! $outage->isRunning()) {
            $context[__('uptime.alert.context_duration')] = self::duration($outage);
        }

        return $context;
    }

    /**
     * Die Dauer als Text — dieselbe Darstellung wie überall sonst, deshalb über
     * {@see Formats::duration()} und in Millisekunden hinein.
     */
    private static function duration(UptimeOutage $outage): string
    {
        return Formats::duration($outage->duration() * 1000);
    }

    /**
     * Der Link führt auf die Übersicht des Projekts, nicht auf den einzelnen
     * Vorfall: wer eine solche Meldung liest, will als Erstes wissen, ob nur
     * dieses eine Ziel betroffen ist.
     */
    private function url(UptimeMonitor $monitor): string
    {
        return route('projects.uptime.index', [
            $monitor->project->organization,
            $monitor->project,
        ]);
    }
}
