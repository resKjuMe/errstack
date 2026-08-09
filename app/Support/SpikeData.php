<?php

namespace App\Support;

use App\Enums\DiscardReason;
use App\Models\IngestDiscard;
use App\Models\IngestVolume;
use App\Models\Project;
use App\Models\SpikeProtectionState;
use App\Models\User;
use App\Support\Ingest\Spikes\SpikeBaseline;
use App\Support\Ingest\Spikes\SpikeCounter;
use Illuminate\Support\Facades\Gate;

/**
 * Nutzlast der Ausschlag-Schutz-Seite eines Projekts: die Einstellungen, der
 * laufende Zustand, der Verlauf und — die eigentliche Zusage — die Zahl der
 * gedrosselten Ereignisse.
 *
 * Die Zahl steht **auf derselben Seite** wie der Schalter, aus demselben Grund
 * wie bei den Eingangsfiltern ({@see InboundFilterData}), nur zwingender: eine
 * Drosselung wirft Meldungen weg, und weggeworfene Meldungen hinterlassen in
 * der Fehlerliste keine Lücke. Wer nicht sieht, wie viel sie genommen hat,
 * erfährt es überhaupt nicht.
 *
 * Der Verlauf der letzten Stunde steht daneben, weil die Schwelle sonst eine
 * Zahl ohne Bezug wäre: „ab 4.500 je Minute" beantwortet die Frage „ist das
 * viel?" erst, wenn die tatsächlichen Minuten daneben stehen.
 */
final class SpikeData
{
    /**
     * Wie viele Minuten der Verlauf auf der Seite zeigt.
     *
     * Eine Stunde: genug, um eine Spitze samt Anlauf und Abklingen zu sehen,
     * und wenig genug, dass die Zahlenreihe im Browser noch eine Reihe bleibt
     * und keine Wolke.
     */
    public const CHART_MINUTES = 60;

    /**
     * Wie viele vergangene Auslösungen aufgeführt werden.
     */
    public const HISTORY_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public static function forProject(Project $project, User $viewer): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $mayManage = Gate::forUser($viewer)->allows('update', $project);
        $baseline = SpikeBaseline::for($project);
        $open = SpikeProtectionState::open($project);

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'enabled' => $project->spike_protection_enabled,
                'factor' => $project->spike_threshold_factor,
                'minimumEvents' => $project->spike_minimum_events,
                'releaseMinutes' => $project->spike_release_minutes,
            ],
            // Woran gerade gemessen wird. `ready` ist die ehrliche Antwort auf
            // „warum drosselt er nicht?": solange zu wenig Verlauf vorliegt,
            // entscheidet der Schutz bewusst gar nicht.
            'detection' => [
                'baseline' => round($baseline->baseline, 1),
                'threshold' => $baseline->threshold(),
                'samples' => $baseline->samples,
                'ready' => $baseline->isReady(),
                'requiredSamples' => SpikeBaseline::MINIMUM_SAMPLES,
                'historyMinutes' => SpikeBaseline::HISTORY_MINUTES,
            ],
            'current' => $open === null ? null : self::state($open),
            'history' => SpikeProtectionState::query()
                ->latestFirst($project)
                ->whereNotNull('ended_at')
                ->with('releasedBy')
                ->limit(self::HISTORY_LIMIT)
                ->get()
                ->map(fn (SpikeProtectionState $state): array => self::state($state))
                ->all(),
            'volumes' => self::volumes($project),
            // Was die Drosselung insgesamt gekostet hat, über alle Vorfälle:
            // die eine Zahl, nach der jemand sucht, der wissen will, ob ihm
            // Meldungen fehlen.
            'discardedTotal' => (int) IngestDiscard::query()
                ->where('project_id', $project->id)
                ->where('reason', DiscardReason::Throttled->value)
                ->sum('quantity'),
            'canManage' => $mayManage,
            'hrefs' => [
                'update' => route('projects.spikes.update', [$organization, $project]),
                'release' => route('projects.spikes.release', [$organization, $project]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(SpikeProtectionState $state): array
    {
        return [
            'id' => $state->id,
            'startedAt' => Formats::dateTime($state->started_at),
            'endedAt' => Formats::dateTime($state->ended_at),
            'baseline' => round($state->baseline, 1),
            'threshold' => $state->threshold,
            'peak' => $state->peak,
            'discarded' => $state->discarded,
            'releasedBy' => $state->releasedBy?->name,
        ];
    }

    /**
     * Der Verlauf der letzten Stunde, älteste Minute zuerst.
     *
     * Die laufende, noch nicht abgeschlossene Minute kommt aus dem Zähler im
     * Zwischenspeicher und nicht aus der Datenbank — dort steht sie erst, wenn
     * sie vorbei ist. Ohne sie wäre die Seite genau in dem Moment eine Minute
     * blind, in dem jemand sie aufruft: während der Flut.
     *
     * @return list<array{minute: string, quantity: int, throttled: bool, partial: bool}>
     */
    private static function volumes(Project $project): array
    {
        $rows = IngestVolume::query()
            ->recent($project, self::CHART_MINUTES)
            ->get()
            ->sortBy('bucket')
            ->values()
            ->map(fn (IngestVolume $volume): array => [
                'minute' => $volume->bucket->toIso8601String(),
                'quantity' => $volume->quantity,
                'throttled' => $volume->throttled,
                'partial' => false,
            ])
            ->all();

        $running = app(SpikeCounter::class)->peek($project);
        $now = IngestVolume::bucket();

        // Ein Projekt, das noch nie etwas gemeldet hat, bekommt keinen einzelnen
        // Balken auf Null: das sähe aus wie eine Messung und ist keine. Die Seite
        // sagt in dem Fall lieber, dass noch kein Verlauf da ist.
        if ($rows === [] && $running['events'] === 0) {
            return [];
        }

        $rows[] = [
            'minute' => $now->toIso8601String(),
            'quantity' => $running['events'],
            'throttled' => $running['discarded'] > 0,
            'partial' => true,
        ];

        return array_values($rows);
    }
}
