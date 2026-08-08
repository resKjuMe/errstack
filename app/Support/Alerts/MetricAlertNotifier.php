<?php

namespace App\Support\Alerts;

use App\Enums\AlertComparison;
use App\Models\MetricAlert;
use App\Models\MetricAlertTransition;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Crons\CronAlerts;
use App\Support\Formats;
use Illuminate\Support\Carbon;

/**
 * Was hinausgeht, wenn ein Alarm seinen Zustand wechselt.
 *
 * Die Entscheidung **ob** gemeldet wird, ist vorher gefallen
 * ({@see MetricAlertEvaluator}); hier steht nur, **was** dann rausgeht. Derselbe
 * Schnitt wie bei der Cronjob-Überwachung ({@see CronAlerts})
 * und aus demselben Grund: der Weg über {@see NotificationDispatcher} und die
 * Kanäle der Organisation (A1) ist der einzige, den es geben darf — ein eigener
 * Versandweg für Kennzahlen wäre eine zweite Stelle, an der jemand seine
 * Ruhezeiten einstellen müsste.
 *
 * **Die Entwarnung ist kein Beiwerk.** Wer nur das Auslösen meldet, zwingt jeden
 * Empfänger, selbst nachzusehen, ob es sich erledigt hat — und genau das
 * unterbleibt dann.
 *
 * **Die Stummschaltung greift hier** ({@see AlertMute}) und nicht eine Stufe
 * früher: der Zustandswechsel ist bereits festgestellt und steht im Verlauf,
 * wenn dieser Weg beschritten wird. Wer Ruhe wollte, bekommt sie — und
 * anschließend trotzdem die Auskunft, was in dieser Zeit passiert ist.
 */
final class MetricAlertNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function send(MetricAlert $alert, MetricAlertTransition $transition): void
    {
        // Stummgeschaltet (A4): der Wechsel steht bereits im Verlauf, nur der
        // Versand unterbleibt. Die Prüfung steht hier und nicht in der
        // Auswertung — genau darin liegt die Zusage, dass eine Stummschaltung
        // die Überwachung nicht abschaltet.
        //
        // Gefragt wird ohne Person: ein Schwellwert-Alarm meldet an die
        // gemeinsamen Kanäle der Organisation und an niemanden persönlich. Eine
        // persönliche Stummschaltung kann daran nichts leiser machen.
        if (AlertMute::for($alert)->mutes()) {
            return;
        }

        $project = $alert->project;

        $this->dispatcher->send($project->organization, new NotificationMessage(
            title: __('alerts.notification.'.$transition->kind().'_title', [
                'alert' => $alert->name,
                'project' => $project->name,
            ]),
            body: $this->body($alert, $transition),
            // Der Grad folgt dem **Ziel** des Wechsels und nicht seiner
            // Richtung: eine Entspannung von kritisch auf Warnung ist immer
            // noch eine Warnung, keine gute Nachricht.
            level: $transition->to_status->notificationLevel(),
            url: route('projects.alerts.index', [$project->organization, $project]),
            context: $this->context($alert, $transition),
            // Dieselbe Kennung über alle Meldungen eines Alarms: erst dadurch
            // lassen sich Auslösen und Entwarnung im Kanal einander zuordnen.
            reference: AlertReference::forMetricAlert($alert),
            // Als veränderliches Carbon: die Nachricht nimmt genau das, und ein
            // unveränderliches ist kein Untertyp davon.
            occurredAt: Carbon::parse($transition->occurred_at),
        ));
    }

    /**
     * Der Text sagt, was gemessen wurde und woran es gemessen wurde.
     *
     * „Der Alarm hat ausgelöst" allein hilft niemandem, der entscheiden soll, ob
     * er aufstehen muss — die Zahl und die Schwelle daneben tun es.
     */
    private function body(MetricAlert $alert, MetricAlertTransition $transition): string
    {
        $replace = [
            'alert' => $alert->name,
            'project' => $alert->project->name,
            'metric' => $alert->metric->label(),
            'value' => self::number($alert, $transition->value),
            'threshold' => $transition->threshold === null
                ? __('alerts.notification.no_threshold')
                : self::number($alert, $transition->threshold),
            'minutes' => (string) $alert->window_minutes,
        ];

        return __('alerts.notification.'.$transition->kind().'_body', $replace);
    }

    /**
     * @return array<string, string>
     */
    private function context(MetricAlert $alert, MetricAlertTransition $transition): array
    {
        $context = [
            __('alerts.notification.context_project') => $alert->project->name,
            __('alerts.notification.context_metric') => $alert->metric->label(),
            __('alerts.notification.context_window') => __('alerts.notification.minutes', [
                'minutes' => (string) $alert->window_minutes,
            ]),
            __('alerts.notification.context_value') => self::number($alert, $transition->value),
            __('alerts.notification.context_status') => $transition->to_status->label(),
        ];

        if ($alert->environment !== null) {
            $context[__('alerts.notification.context_environment')] = $alert->environment;
        }

        if ($alert->transaction_name !== null) {
            $context[__('alerts.notification.context_transaction')] = $alert->transaction_name;
        }

        if ($alert->comparison === AlertComparison::PercentChangeWeek && $transition->baseline !== null) {
            $context[__('alerts.notification.context_baseline')] = Formats::number(
                $transition->baseline,
                $alert->metric->decimals(),
            ).self::suffix($alert->metric->unit());
        }

        return $context;
    }

    /**
     * Eine Zahl, wie sie dasteht — samt Einheit.
     *
     * Geschrieben wird sie serverseitig: wie eine Zahl aussieht, entscheidet die
     * Sprache, und eine Benachrichtigung hat keinen Browser, der das nachholen
     * könnte.
     */
    private static function number(MetricAlert $alert, float $value): string
    {
        $decimals = $alert->comparison === AlertComparison::PercentChangeWeek
            ? 1
            : $alert->metric->decimals();

        return Formats::number($value, $decimals).self::suffix($alert->unit());
    }

    private static function suffix(string $unit): string
    {
        return $unit === '' ? '' : ' '.$unit;
    }
}
