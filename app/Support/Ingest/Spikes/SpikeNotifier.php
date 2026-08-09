<?php

namespace App\Support\Ingest\Spikes;

use App\Enums\NotificationLevel;
use App\Models\Project;
use App\Models\SpikeProtectionState;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Alerts\AlertReference;
use App\Support\Alerts\MetricAlertNotifier;
use App\Support\Formats;
use Illuminate\Support\Carbon;

/**
 * Was hinausgeht, wenn der Ausschlag-Schutz greift — und wenn er wieder
 * loslässt.
 *
 * Derselbe Schnitt wie bei den Schwellwert-Alarmen
 * ({@see MetricAlertNotifier}): die Entscheidung ist
 * vorher gefallen, hier steht nur, **was** dann rausgeht. Und derselbe Weg —
 * über {@see NotificationDispatcher} an die Kanäle der Organisation (A1) —,
 * damit niemand seine Ruhezeiten an einer zweiten Stelle einstellen muss.
 *
 * **Die Entwarnung ist kein Beiwerk.** Wer nur das Auslösen meldet, lässt jeden
 * Empfänger im Glauben, es würde immer noch verworfen; die Meldung darüber, dass
 * die Aufnahme wieder vollständig läuft, ist die Hälfte der Auskunft.
 *
 * **Beide Meldungen sind dringend** und werden nie gebündelt (A6): eine
 * Sammelnachricht in einer Stunde träfe genau dann ein, wenn die Daten schon
 * verloren sind. Der Grad ist trotzdem `Warning` und nicht `Error` — die
 * Aufnahme läuft, sie ist gedeckelt; kaputt ist die meldende Anwendung.
 */
final class SpikeNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Die Drosselung hat begonnen.
     */
    public function triggered(Project $project, SpikeProtectionState $state, int $observed): void
    {
        $project->loadMissing('organization');

        $this->dispatcher->send($project->organization, new NotificationMessage(
            title: __('spikes.notification.triggered_title', ['project' => $project->name]),
            body: __('spikes.notification.triggered_body', [
                'project' => $project->name,
                'observed' => Formats::number($observed),
                'threshold' => Formats::number($state->threshold),
                'baseline' => Formats::number($state->baseline, 1),
            ]),
            level: NotificationLevel::Warning,
            url: route('projects.spikes.index', [$project->organization, $project]),
            context: [
                __('spikes.notification.context_project') => $project->name,
                __('spikes.notification.context_observed') => Formats::number($observed),
                __('spikes.notification.context_threshold') => Formats::number($state->threshold),
                __('spikes.notification.context_baseline') => Formats::number($state->baseline, 1),
            ],
            reference: AlertReference::forSpikeProtection($state->id),
            occurredAt: Carbon::parse($state->started_at),
            urgent: true,
        ));
    }

    /**
     * Die Drosselung ist vorbei — von selbst oder von Hand aufgehoben.
     *
     * Eine Methode für beides, weil es dieselbe Nachricht mit einem anderen
     * Satz ist: was zählt, ist „es wird wieder alles angenommen", und daneben
     * die Zahl, die niemand vergessen soll — wie viel in der Zwischenzeit
     * verworfen wurde.
     */
    public function ended(Project $project, SpikeProtectionState $state): void
    {
        $project->loadMissing('organization');
        $state->loadMissing('releasedBy');

        $key = $state->wasReleasedByHand() ? 'released' : 'recovered';

        $this->dispatcher->send($project->organization, new NotificationMessage(
            title: __('spikes.notification.'.$key.'_title', ['project' => $project->name]),
            body: __('spikes.notification.'.$key.'_body', [
                'project' => $project->name,
                'discarded' => Formats::number($state->discarded),
                'minutes' => Formats::number($this->minutes($state)),
                'user' => $state->releasedBy->name ?? __('spikes.notification.unknown_user'),
            ]),
            level: NotificationLevel::Info,
            url: route('projects.spikes.index', [$project->organization, $project]),
            context: [
                __('spikes.notification.context_project') => $project->name,
                __('spikes.notification.context_discarded') => Formats::number($state->discarded),
                __('spikes.notification.context_duration') => __('spikes.notification.minutes', [
                    'minutes' => Formats::number($this->minutes($state)),
                ]),
            ],
            // Dieselbe Kennung wie beim Auslösen: erst dadurch finden Auslösen
            // und Entwarnung im Kanal zueinander.
            reference: AlertReference::forSpikeProtection($state->id),
            occurredAt: $state->ended_at === null ? Carbon::now() : Carbon::parse($state->ended_at),
            urgent: true,
        ));
    }

    /**
     * Wie lange gedrosselt wurde, in vollen Minuten — mindestens eine.
     *
     * „0 Minuten" wäre die eine Angabe, die sicher falsch ist: verworfen wurde
     * ja etwas.
     */
    private function minutes(SpikeProtectionState $state): int
    {
        $end = $state->ended_at ?? Carbon::now();

        return max(1, (int) $state->started_at->diffInMinutes($end));
    }
}
