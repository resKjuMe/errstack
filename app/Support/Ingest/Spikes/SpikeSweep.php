<?php

namespace App\Support\Ingest\Spikes;

use App\Enums\DiscardReason;
use App\Models\IngestDiscard;
use App\Models\IngestVolume;
use App\Models\Project;
use App\Models\SpikeProtectionState;
use Illuminate\Support\Carbon;

/**
 * Der minütliche Durchlauf des Ausschlag-Schutzes (A7).
 *
 * Er ist die Buchhaltung hinter einer Entscheidung, die aus Geschwindigkeits-
 * gründen nichts aufschreiben darf: die Aufnahme zählt nur im Zwischenspeicher
 * ({@see SpikeCounter}) und entscheidet aus einem fertigen Zustand
 * ({@see SpikeStatus}). Hier wird beides eingeholt — je Projekt einmal in der
 * Minute:
 *
 *   1. Die abgeschlossene Minute wird abgeholt und als Zeile im Verlauf
 *      festgeschrieben ({@see IngestVolume}).
 *   2. Was die Drosselung in dieser Minute verworfen hat, wird gezählt: als
 *      Verwerfung mit dem Grund `throttled` und am Vorfall selbst. **Das ist
 *      die Einlösung der Zusage**, dass gedrosselte Ereignisse nie
 *      stillschweigend verschwinden.
 *   3. Der Vergleichswert wird neu gebildet und der Zustand aufgefrischt.
 *   4. Eine laufende Drosselung wird beendet, wenn sich die Menge beruhigt hat
 *      — mit Entwarnung.
 *
 * Läuft der Zeitplan nicht, hat das eine milde Folge und keine schlimme: es
 * wird nicht gedrosselt (der Verlauf wächst nicht, und ohne genug Verlauf
 * entscheidet der Schutz nicht). Eine bereits laufende Drosselung endet dann
 * allerdings auch nicht von selbst — deshalb der Knopf zum Aufheben von Hand.
 */
final class SpikeSweep
{
    /**
     * Wie viele beruhigte Minuten in Folge die Drosselung beenden.
     *
     * Zwei, nicht eine: die erste ruhige Minute nach einer Flut ist oft die
     * Minute, in der das SDK der meldenden Anwendung gerade seinen eigenen
     * Wartezustand abwartet. Bei einer einzigen Minute als Maßstab ginge die
     * Drosselung auf und sofort wieder zu — und jeder Wechsel ist eine
     * Benachrichtigung.
     */
    private const CALM_MINUTES = 2;

    public function __construct(
        private readonly SpikeCounter $counter,
        private readonly SpikeNotifier $notifier,
    ) {}

    /**
     * @return array{projects: int, throttling: int, ended: int, discarded: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        // Die **abgeschlossene** Minute, nicht die laufende: eine Minute, in
        // die noch gezählt wird, wäre im Verlauf ein zu niedriger Wert und
        // würde den Vergleichswert nach unten ziehen.
        $bucket = IngestVolume::bucket($now)->subMinute();

        $result = ['projects' => 0, 'throttling' => 0, 'ended' => 0, 'discarded' => 0];

        // `lazyById` und nicht `get()`: die Zahl der Projekte mit
        // eingeschaltetem Schutz ist nach oben offen, und der Durchlauf läuft
        // jede Minute — alle auf einmal zu laden wäre der eine Speicherverbrauch,
        // der mit der Installation wächst.
        $projects = Project::query()
            ->where('spike_protection_enabled', true)
            ->with('organization')
            ->lazyById(100);

        foreach ($projects as $project) {
            $outcome = $this->sweep($project, $bucket);

            $result['projects']++;
            $result['discarded'] += $outcome['discarded'];
            $result['throttling'] += $outcome['throttling'] ? 1 : 0;
            $result['ended'] += $outcome['ended'] ? 1 : 0;
        }

        // Einmal je Stunde aufräumen und nicht jede Minute: der Verlauf ist
        // eine Zeile je Projekt und Minute, das Löschen der Zeilen von vorletzter
        // Woche eilt nicht — und ein Löschbefehl je Minute wäre auf einer großen
        // Tabelle sechzigmal so viel Arbeit für dasselbe Ergebnis.
        if ((int) $bucket->minute === 0) {
            IngestVolume::query()
                ->where('bucket', '<', $bucket->copy()->subDays(IngestVolume::RETAINED_DAYS))
                ->delete();
        }

        return $result;
    }

    /**
     * @return array{discarded: int, throttling: bool, ended: bool}
     */
    private function sweep(Project $project, Carbon $bucket): array
    {
        $state = SpikeProtectionState::open($project);
        $counts = $this->counter->take($project, $bucket);

        // **Nicht** `$state`, sondern die Drosselung, die in dieser Minute lief:
        // wurde von Hand aufgehoben, ist sie bereits geschlossen. Die
        // Unterscheidung entscheidet über beides — ob die Minute im Verlauf als
        // gedrosselt gilt (sonst hübe die Spitze ihren eigenen Vergleichswert
        // an) und in welche Bilanz die letzten Sekunden gehören.
        $covering = SpikeProtectionState::covering($project, $bucket);

        IngestVolume::record($project, $bucket, $counts['events'], $covering !== null);

        if ($counts['discarded'] > 0) {
            $this->book($project, $covering, $counts['discarded'], $counts['events']);
        }

        $baseline = SpikeBaseline::for($project);
        $ended = false;

        if ($state !== null && $this->hasCalmedDown($project, $baseline, $bucket)) {
            // Frisch lesen: die Zahlen wurden eben fortgeschrieben, und die
            // Entwarnung soll die endgültige Summe nennen.
            $state->refresh();
            $state->finish();

            $this->notifier->ended($project, $state);

            $state = null;
            $ended = true;
        }

        // Den Zustand für die Aufnahme aus der Datenbank herstellen statt ihn
        // hier zusammenzusetzen: er enthält auch die Ruhefrist nach einem
        // Aufheben von Hand, und die noch einmal nachzubilden hieße, dieselbe
        // Regel an zwei Stellen zu pflegen.
        SpikeStatus::refresh($project);

        return ['discarded' => $counts['discarded'], 'throttling' => $state !== null, 'ended' => $ended];
    }

    /**
     * Schreibt fest, was die Drosselung in dieser Minute verworfen hat.
     */
    private function book(Project $project, ?SpikeProtectionState $state, int $discarded, int $observed): void
    {
        IngestDiscard::forProject($project, DiscardReason::Throttled, null, $discarded);

        if ($state === null) {
            return;
        }

        $state->increment('discarded', $discarded);

        if ($observed > $state->peak) {
            $state->forceFill(['peak' => $observed])->save();
        }
    }

    /**
     * Hat sich die Menge lange genug beruhigt?
     *
     * Gemessen an den zuletzt festgeschriebenen Minuten und nicht an einem
     * Zähler im Zwischenspeicher: der Verlauf ist die Quelle, auf die sich auch
     * die Schwelle stützt, und eine zweite Zählung für dieselbe Frage wäre eine
     * zweite Wahrheit.
     */
    private function hasCalmedDown(Project $project, SpikeBaseline $baseline, Carbon $bucket): bool
    {
        $recent = IngestVolume::query()
            ->recent($project, self::CALM_MINUTES)
            ->get();

        if ($recent->count() < self::CALM_MINUTES) {
            return false;
        }

        // Die eben geschriebene Minute muss dabei sein: liegt der Verlauf
        // still (ausgefallener Zeitplan), sind die letzten Zeilen alt, und
        // „ruhig" hieße dann nur „es hat lange niemand nachgesehen".
        if (! $recent->first()?->bucket->equalTo($bucket)) {
            return false;
        }

        return $recent->every(
            fn (IngestVolume $volume): bool => $baseline->hasCalmedDown((int) $volume->quantity)
        );
    }
}
