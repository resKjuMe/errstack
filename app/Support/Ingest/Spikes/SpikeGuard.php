<?php

namespace App\Support\Ingest\Spikes;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\SpikeProtectionState;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Der Ausschlag-Schutz an der Aufnahme (A7): zählt jedes Ereignis und
 * entscheidet, ob es noch angenommen wird.
 *
 * Diese Klasse liegt auf dem heißesten Weg der Anwendung — sie wird bei jedem
 * eingehenden Ereignis gefragt, und zwar auch (gerade) dann, wenn eine
 * fehlerhafte Auslieferung Millionen davon erzeugt. Ihr Preis ist deshalb die
 * Vorgabe für alles, was sie tut: **zwei Zugriffe auf den Zwischenspeicher, kein
 * Datenbankzugriff**. Der Zustand steht fertig gerechnet in
 * {@see SpikeStatus}, der Zähler in {@see SpikeCounter}, und beides frischt der
 * minütliche Durchlauf auf.
 *
 * Die eine Ausnahme ist das Auslösen selbst: dort entsteht eine Zeile und geht
 * eine Benachrichtigung hinaus. Das passiert **einmal** je Vorfall und ist
 * gegen den Wettlauf paralleler Anfragen gesperrt.
 *
 * **Verworfen wird nie stillschweigend.** Jedes gedrosselte Ereignis erhöht
 * einen Zähler; festgeschrieben werden die Zahlen gesammelt je Minute
 * ({@see SpikeSweep}) — als Verwerfung mit dem Grund
 * {@see DiscardReason::Throttled} und am Vorfall selbst. Einzeln in
 * die Datenbank zu schreiben wäre genau der Schreibsturm, den die Drosselung
 * verhindern soll.
 */
final class SpikeGuard
{
    /**
     * Wie lange die Sperre gilt, unter der eine Drosselung eröffnet wird.
     *
     * Kurz: sie schützt gegen den Wettlauf zweier gleichzeitiger Anfragen, nicht
     * gegen einen langen Vorgang. Bleibt sie hängen — abgestürzter Arbeiter —,
     * ist die Folge eine Drosselung, die ein paar Sekunden später beginnt.
     */
    private const LOCK_SECONDS = 10;

    public function __construct(
        private readonly SpikeCounter $counter,
        private readonly SpikeNotifier $notifier,
    ) {}

    /**
     * Nimmt ein Ereignis zur Kenntnis und sagt, ob es angenommen wird.
     *
     * Gezählt wird **vor** der Entscheidung und unabhängig von ihr: der Verlauf
     * soll sagen, wie viel eine Anwendung gemeldet hat, nicht wie viel davon
     * wir behalten haben. Andernfalls sähe eine laufende Drosselung im Verlauf
     * aus wie eine Anwendung, die sich beruhigt hat — und der Vergleichswert
     * ginge mit ihr nach unten.
     *
     * @param  IngestType  $type  Art der Meldung. Gedrosselt wird nur, was auch
     *                            gegen das Kontingent zählt: ein Lebenszeichen,
     *                            eine Verworfen-Meldung des SDK und ein Anhang
     *                            sind keine Ereignisse, sondern Angaben über
     *                            welche — und ausgerechnet die Auskunft
     *                            wegzuwerfen, während wir wegwerfen, wäre
     *                            widersinnig.
     */
    public function allows(ProjectKey $key, IngestType $type = IngestType::Event): bool
    {
        if (! $type->countsTowardEventQuota()) {
            return true;
        }

        $key->loadMissing('project');
        $project = $key->project;

        if ($project === null) {
            return true;
        }

        $status = SpikeStatus::for($project);

        if (! $status->enabled) {
            return true;
        }

        $observed = $this->counter->count($project);

        if ($status->isThrottling()) {
            $this->counter->countDiscarded($project);

            return false;
        }

        if (! $status->mayTrigger() || $observed <= $status->threshold) {
            return true;
        }

        $this->trigger($project, $status, $observed);
        $this->counter->countDiscarded($project);

        return false;
    }

    /**
     * Hebt eine laufende Drosselung von Hand auf.
     *
     * Die Ruhefrist danach steckt nicht hier, sondern in {@see SpikeStatus}: sie
     * ergibt sich aus dem Zeitpunkt des Aufhebens und der Einstellung des
     * Projekts, und beides steht ohnehin in der Datenbank. Ein zusätzlicher
     * Merker wäre ein zweiter Ort für dieselbe Aussage.
     *
     * @return bool Ob es überhaupt etwas aufzuheben gab.
     */
    public function release(Project $project, User $by): bool
    {
        $state = SpikeProtectionState::open($project);

        if ($state === null) {
            return false;
        }

        $state->finish($by);

        $this->notifier->ended($project, $state);

        SpikeStatus::refresh($project);

        return true;
    }

    /**
     * Eröffnet die Drosselung — einmal je Vorfall.
     *
     * Unter einer Sperre, weil in dieser Sekunde sehr viele Anfragen gleichzeitig
     * über die Schwelle stolpern: ohne sie entstünden hundert Zeilen und hundert
     * Benachrichtigungen für denselben Vorfall. Wer die Sperre nicht bekommt,
     * verwirft trotzdem — die Entscheidung ist ja gefallen, es fehlt nur noch
     * ihr Protokoll.
     */
    private function trigger(Project $project, SpikeStatus $status, int $observed): void
    {
        $lock = Cache::lock('spike:trigger:'.$project->id, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            // Zwischen Schwellenprüfung und Sperre kann ein anderer Arbeiter
            // bereits eröffnet haben.
            if (SpikeProtectionState::open($project) !== null) {
                return;
            }

            $state = SpikeProtectionState::start($project, $status->baseline, $status->threshold, $observed);

            $status->withState($state->id)->store($project);

            Log::warning('Ausschlag-Schutz greift: Aufnahme wird gedrosselt.', [
                'projekt' => $project->id,
                'beobachtet' => $observed,
                'schwelle' => $status->threshold,
                'verlaufswert' => $status->baseline,
            ]);

            $this->notifier->triggered($project, $state, $observed);
        } finally {
            $lock->release();
        }
    }
}
