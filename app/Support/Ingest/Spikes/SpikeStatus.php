<?php

namespace App\Support\Ingest\Spikes;

use App\Models\Project;
use App\Models\SpikeProtectionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Alles, was die Aufnahme über den Ausschlag-Schutz eines Projekts wissen
 * muss — in **einem** Wert und aus **einem** Zugriff auf den Zwischenspeicher.
 *
 * Das ist der eigentliche Zweck der Klasse. Die Aufnahme fragt bei jedem
 * Ereignis „drosseln wir gerade, und ab wann?"; wären das zwei Abfragen auf die
 * Datenbank, hätte der Schutz gegen die Flut denselben Preis wie die Flut. Die
 * Angaben ändern sich höchstens einmal je Minute, sie stehen deshalb fertig
 * gerechnet im Zwischenspeicher und werden vom Durchlauf ({@see SpikeSweep})
 * sowie beim Auslösen und Aufheben aufgefrischt.
 *
 * Fehlt der Eintrag (frischer Cache, Neustart), wird er aus der Datenbank
 * hergestellt — und zwar mit einer kurzen Verfallszeit, damit ein leerer Cache
 * nicht dazu führt, dass gar nicht mehr gedrosselt wird.
 */
final readonly class SpikeStatus
{
    /**
     * Wie lange ein selbst hergestellter Eintrag gilt.
     *
     * Eine Minute: derselbe Takt, in dem der Durchlauf ihn ohnehin auffrischt.
     * Länger hieße, dass eine gerade beendete Drosselung noch minutenlang
     * verwirft; kürzer hieße, dass jede Sekunde jemand die Datenbank fragt.
     */
    private const TTL_SECONDS = 60;

    public function __construct(
        public bool $enabled,
        /** Menge je Minute, ab der gedrosselt wird; `0` heißt: nicht drosseln. */
        public int $threshold,
        public float $baseline,
        /** Kennung der laufenden Drosselung, sofern eine läuft. */
        public ?int $stateId = null,
        /** Bis wann nach einem Aufheben von Hand Ruhe ist. */
        public ?Carbon $quietUntil = null,
    ) {}

    public static function for(Project $project): self
    {
        $cached = Cache::get(self::key($project));

        return $cached instanceof self ? $cached : self::refresh($project);
    }

    /**
     * Liest den Zustand aus der Datenbank und legt ihn in den Zwischenspeicher.
     */
    public static function refresh(Project $project): self
    {
        $status = self::fromDatabase($project);

        $status->store($project);

        return $status;
    }

    public function store(Project $project): void
    {
        Cache::put(self::key($project), $this, self::TTL_SECONDS);
    }

    /**
     * Wird gerade gedrosselt?
     */
    public function isThrottling(): bool
    {
        return $this->stateId !== null;
    }

    /**
     * Darf eine Menge über der Schwelle jetzt eine Drosselung auslösen?
     *
     * Nein, solange die Ruhefrist nach einem Aufheben von Hand läuft. Ohne sie
     * wäre der Knopf wirkungslos: die Flut läuft ja weiter, und die nächste
     * Minute löste sofort wieder aus — der Betreiber, der ausdrücklich
     * entschieden hat, die Meldungen durchzulassen, müsste im Sekundentakt
     * dagegen anklicken.
     */
    public function mayTrigger(?Carbon $now = null): bool
    {
        if (! $this->enabled || $this->threshold <= 0 || $this->isThrottling()) {
            return false;
        }

        return $this->quietUntil === null || $this->quietUntil->lessThanOrEqualTo($now ?? Carbon::now());
    }

    /**
     * Derselbe Zustand mit einer laufenden Drosselung — der Weg, das Auslösen
     * sofort für alle Arbeiter sichtbar zu machen, ohne dafür die Datenbank zu
     * fragen.
     *
     * Die Ruhefrist fällt dabei weg, und zwar richtigerweise: ausgelöst wird nur,
     * wenn keine läuft ({@see self::mayTrigger()}) — es gäbe hier also nichts zu
     * übernehmen.
     */
    public function withState(int $stateId): self
    {
        return new self(
            enabled: $this->enabled,
            threshold: $this->threshold,
            baseline: $this->baseline,
            stateId: $stateId,
        );
    }

    private static function fromDatabase(Project $project): self
    {
        if (! $project->spike_protection_enabled) {
            return new self(enabled: false, threshold: 0, baseline: 0.0);
        }

        $baseline = SpikeBaseline::for($project);
        $open = SpikeProtectionState::open($project);

        return new self(
            enabled: true,
            threshold: $baseline->threshold(),
            baseline: $baseline->baseline,
            stateId: $open?->id,
            quietUntil: self::quietUntil($project),
        );
    }

    /**
     * Bis wann die Ruhefrist der zuletzt von Hand aufgehobenen Drosselung
     * läuft.
     */
    private static function quietUntil(Project $project): ?Carbon
    {
        $minutes = (int) $project->spike_release_minutes;

        if ($minutes < 1) {
            return null;
        }

        $released = SpikeProtectionState::query()
            ->whereNotNull('released_at')
            ->latestFirst($project)
            ->first();

        if ($released?->released_at === null) {
            return null;
        }

        $until = $released->released_at->copy()->addMinutes($minutes);

        return $until->isFuture() ? $until : null;
    }

    private static function key(Project $project): string
    {
        return 'spike:status:'.$project->id;
    }
}
