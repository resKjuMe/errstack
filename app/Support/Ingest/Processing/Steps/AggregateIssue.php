<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Events\IssueCreated;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Schreibt einen Fehler-Eintrag fort: Häufigkeit, Betroffene, erstes und
 * letztes Auftreten, Verlauf.
 *
 * Der Schritt, an dem aus einer Gruppe eine Aussage wird. Die Gruppierung davor
 * sagt, **welche** Meldungen zusammengehören; hier entsteht die Antwort auf
 * „wie oft, seit wann, wen trifft es" — und damit alles, woran später Alarme
 * und Diagramme hängen.
 *
 * **Warum ein eigener Schritt und nicht ein paar Zeilen im Gruppieren:** die
 * beiden haben unvereinbare Anforderungen. Das Gruppieren muss auf Dauer
 * dasselbe Ergebnis liefern und darf dafür rechnen; das Zählen muss sperrfrei
 * sein und darf dafür nichts wissen. In einem Schritt wäre der teure Teil an den
 * heißen gekettet.
 *
 * **Er steht am Ende der Kette**, und das ist keine Bequemlichkeit: gezählt
 * werden darf nur, was auch bleibt. Ein Eingangsfilter (I8) sortiert vor ihm
 * aus, das Scrubbing (I7) räumt vor ihm auf — käme das Zählen früher, stünden in
 * den Zahlen Meldungen, die niemand behalten wollte, und aus einem Zähler ließe
 * sich das nicht wieder herausrechnen.
 */
final class AggregateIssue implements ProcessingStep
{
    /**
     * Der Name, unter dem der Eintrag für die folgenden Schritte bereitliegt.
     *
     * Die Alarmierung (A2) holt ihn hier ab: sie braucht den Eintrag samt
     * Zählern und nicht das einzelne Ereignis — „hundertmal in einer Stunde" ist
     * keine Eigenschaft einer Meldung.
     */
    public const RESULT = 'issue';

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $group = $context->get(GroupEvent::RESULT);
        $record = $context->get(NormalizeEvent::RESULT.'_record');

        if (! $group instanceof EventGroup || ! $record instanceof Event) {
            // Keine Gruppe — ein Anhang, eine Sitzung, eine Meldungsart, für die
            // es (noch) keinen Normalisierer gibt. Durchreichen und **nicht**
            // aussortieren: sie ist kein Fehler, sie gehört nur einem anderen
            // Schritt.
            $next($context);

            return;
        }

        $issue = Issue::forGroup($group, $record);

        // Vor dem Zählen abgegriffen: `record()` schreibt am Eintrag und setzt
        // die Marke zurück.
        $isNew = $issue->wasRecentlyCreated;

        $issue->record($record);

        // Die Fehlerliste (S1) hört mit und trägt einen neuen Eintrag nach, ohne
        // dass jemand die Seite neu lädt. Gemeldet wird nur das **erste**
        // Auftreten: dass ein bekannter Fehler wieder da ist, steht in seinen
        // Zählern — eine Meldung je Ereignis wären bei einem Ausfall tausend in
        // der Minute für denselben Eintrag.
        if ($isNew) {
            event(IssueCreated::fromIssue($issue));
        }

        // Der Eintrag ist nach dem Zählen im Speicher veraltet — die Zähler
        // stehen in der Datenbank, nicht in dieser Instanz. Wer die neuen Werte
        // braucht, holt sie mit `fresh()`; das hier ist der Eintrag, um den es
        // ging, und für die folgenden Schritte genügt das.
        $context->with(self::RESULT, $issue);

        $next($context);
    }
}
