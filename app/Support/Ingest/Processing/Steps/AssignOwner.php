<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Models\Event;
use App\Models\Issue;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Ownership\OwnershipAssignment;
use Closure;

/**
 * Weist einen neu entstandenen Fehler dem zu, dem er nach den
 * Zuständigkeits-Regeln gehört (R6).
 *
 * **Nur beim ersten Auftreten**, und das ist die wichtigste Festlegung dieses
 * Schritts. Bei **jeder** Meldung zu prüfen wäre der naheliegende Weg und der
 * falsche, gleich zweimal:
 *
 * - Fachlich: wer eine Zuständigkeit von Hand aufhebt, sagt damit „nicht ich".
 *   Ein Schritt, der jeden unbeanspruchten Eintrag wieder zuweist, widerspricht
 *   ihm beim nächsten Ereignis — und bei einem Fehler, der minütlich auftritt,
 *   tut er das minütlich.
 * - Betrieblich: das erste Auftreten ist selten, jede weitere Meldung ist der
 *   Regelfall. Ein Regelwerk mit zweihundert Zeilen an den heißen Weg zu hängen
 *   hieße, den Ausfall teurer zu machen, in dem am meisten gemeldet wird.
 *
 * Was das kostet, ist bekannt: ein Fehler, der schon existierte, bevor es die
 * Regel gab, bleibt herrenlos. Genau dafür stehen dieselben Regeln als
 * **Vorschlag** im Zuweisungs-Dialog — dort entscheidet ein Mensch, und die
 * Antwort ist dieselbe.
 *
 * **Er steht direkt hinter dem Fortschreiben** ({@see AggregateIssue}), weil er
 * dessen beide Ergebnisse braucht: den Eintrag und die Auskunft, ob er neu ist.
 * Vor den Alarmen (A2) steht er, damit eine Benachrichtigung über einen neuen
 * Fehler bereits weiß, wer sich kümmert.
 *
 * **Und vor dem verdächtigen Commit** ({@see AssignSuspectCommit}), womit die
 * Rangfolge zwischen beiden festliegt: eine Regel ist eine Entscheidung, ein
 * Abgleich mit einem Commit ist eine Vermutung — und eine Vermutung überstimmt
 * keine Entscheidung. Durchgesetzt wird das ohne Absprache zwischen den beiden
 * Schritten: jeder weist nur zu, was **niemandem** gehört, und wer zuerst
 * kommt, hat damit entschieden.
 *
 * **Fehlschläge sind still.** Eine nicht auflösbare Regel, ein gelöschtes Team,
 * ein Projekt ohne Organisation — nichts davon darf die Aufnahme einer Meldung
 * abbrechen. Der Fehler ist dann eben nicht zugewiesen, und das ist ein
 * Zustand, den die Oberfläche ohnehin kennt.
 */
final class AssignOwner implements ProcessingStep
{
    public function __construct(
        private readonly OwnershipAssignment $assignment,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $issue = $context->get(AggregateIssue::RESULT);
        $record = $context->get(NormalizeEvent::RESULT.'_record');

        if (
            $issue instanceof Issue
            && $record instanceof Event
            && $context->get(AggregateIssue::WAS_NEW) === true
        ) {
            $this->assignment->apply($issue, $record);
        }

        $next($context);
    }
}
