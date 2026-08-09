<?php

namespace App\Support\Releases\Health;

use App\Models\Release;

/**
 * Die Gesundheit einer Auslieferung in einem Zeitraum — als fertige Antwort.
 *
 * Alles, was hier steht, ist entweder gezählt oder aus Gezähltem gerechnet;
 * geschätzt wird nichts. Und was sich nicht sagen lässt, ist `null` und nicht
 * null: aus keiner einzigen Sitzung folgt **keine** Crash-Free-Rate — schon gar
 * nicht „100 %". Genau dieser Unterschied entscheidet darüber, ob ein Alarm auf
 * einer Version anschlägt, aus der gerade gar nichts mehr kommt.
 */
final class ReleaseHealthSummary
{
    /**
     * @param  SessionTally  $sessions  Die Sitzungen des Zeitraums.
     * @param  int  $users  Wie viele Menschen dahinter stehen (`0`, wenn das SDK
     *                      keine Nutzerkennung schickt).
     * @param  int  $crashedUsers  Davon: wem die Anwendung abgestürzt ist.
     * @param  int  $unhealthyUsers  Davon: wem irgendetwas passiert ist —
     *                               Fehler, Absturz oder Abbruch.
     * @param  int  $projectSessions  Alle Sitzungen des Projekts im Zeitraum,
     *                                über alle Versionen. Der Nenner der
     *                                Verbreitung.
     * @param  int  $projectUsers  Dasselbe für die Menschen.
     */
    public function __construct(
        public readonly Release $release,
        public readonly SessionTally $sessions,
        public readonly int $users,
        public readonly int $crashedUsers,
        public readonly int $unhealthyUsers,
        public readonly int $projectSessions,
        public readonly int $projectUsers,
    ) {}

    /**
     * Der Anteil der Sitzungen, die **nicht** abgestürzt sind, in Prozent.
     *
     * Die Zahl, die nach einer Auslieferung als Erstes angesehen wird. Sie zählt
     * nur Abstürze — nicht Fehler, nicht Abbrüche: „absturzfrei" soll dasselbe
     * heißen wie überall sonst, und eine eigene, strengere Auslegung wäre eine
     * Zahl, die sich mit keiner anderen vergleichen lässt.
     */
    public function crashFreeSessions(): ?float
    {
        return $this->sessions->sessions === 0
            ? null
            : (1 - $this->sessions->crashed / $this->sessions->sessions) * 100;
    }

    /**
     * Derselbe Anteil, über Menschen statt über Sitzungen.
     *
     * Die wichtigere der beiden Zahlen und die, die seltener dasteht: sie
     * braucht eine Nutzerkennung in den Meldungen. Ohne die bleibt sie `null` —
     * und nicht etwa 100 %.
     */
    public function crashFreeUsers(): ?float
    {
        return $this->users === 0 ? null : (1 - $this->crashedUsers / $this->users) * 100;
    }

    /**
     * Wie verbreitet die Version ist: der Anteil der Menschen, die sie
     * benutzen, an allen, die im Zeitraum überhaupt unterwegs waren.
     *
     * Wer in einem Zeitraum zwei Versionen benutzt — vor und nach dem Update —,
     * zählt in beiden. Die Anteile addieren sich deshalb nicht zwingend auf
     * hundert, und das ist richtig so: alles andere hieße, jemanden einer
     * Version zuzuschlagen, die er auch benutzt hat.
     */
    public function adoptionUsers(): ?float
    {
        return $this->projectUsers === 0 ? null : $this->users / $this->projectUsers * 100;
    }

    /**
     * Dieselbe Verbreitung über Sitzungen — die Ersatzzahl für alles, was ohne
     * Nutzerkennung meldet.
     */
    public function adoptionSessions(): ?float
    {
        return $this->projectSessions === 0
            ? null
            : $this->sessions->sessions / $this->projectSessions * 100;
    }

    /**
     * Steht überhaupt etwas dahinter?
     *
     * Die Frage, die jede Anzeige zuerst stellt: eine Version ohne Sitzungen ist
     * nicht gesund, sondern unbekannt.
     */
    public function hasData(): bool
    {
        return $this->sessions->sessions > 0;
    }
}
