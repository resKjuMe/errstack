<?php

namespace App\Support\Ownership;

use App\Models\Event;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\OwnershipRule;
use App\Models\Project;
use App\Support\Issues\IssueActions;
use App\Support\Issues\IssueAssignee;
use App\Support\Issues\IssueAssignmentNotifier;

/**
 * Was aus einer zutreffenden Regel wird: ein Vorschlag — oder eine Zuweisung.
 *
 * Die Klasse steht zwischen den Regeln ({@see Ownership}) und der Zuständigkeit
 * ({@see IssueAssignee}) und hat genau eine Aufgabe: aus Text Leute machen. In
 * einer Regel steht `#Kasse` oder `anna@example.com`, denn eine Regel überlebt
 * ein Konto und wird von Hand geschrieben; zugewiesen wird eine Zeile in einer
 * Tabelle. Die Übersetzung dazwischen kann scheitern — jemand ist ausgetreten,
 * das Team heißt anders —, und dass sie scheitern **darf**, ist Absicht: eine
 * Regel, die niemanden mehr benennt, weist eben niemandem etwas zu, statt die
 * Aufnahme einer Meldung mit einem Fehler abzubrechen.
 *
 * **Der erste auflösbare Zuständige bekommt die Zuweisung, vorgeschlagen werden
 * alle.** Eine Zuständigkeit lässt sich nicht teilen — sie ginge sonst an einen
 * von mehreren, und welchen, sähe niemand ({@see IssueAssignee::resolve()} sagt
 * dasselbe über gleichnamige Personen). Ein Vorschlag dagegen darf mehrere
 * anbieten: dort entscheidet ein Mensch.
 */
final class OwnershipAssignment
{
    public function __construct(
        private readonly IssueAssignmentNotifier $notifier,
    ) {}

    /**
     * Die Zuständigen, die für diese Meldung in Frage kommen — der gewinnenden
     * Regel zuerst.
     *
     * Die Reihenfolge ist umgekehrt zur Liste: es gewinnt die **zuletzt**
     * passende Regel, und die gehört im Dialog nach oben. Was weiter unten
     * steht, ist das, was sie überstimmt hat — brauchbar als zweite Wahl und
     * deshalb nicht weggeworfen.
     *
     * @return list<IssueAssignee>
     */
    public function suggest(OwnershipSubjects $subjects, Project $project): array
    {
        $organization = $project->organization;

        if (! $organization instanceof Organization) {
            return [];
        }

        $matches = array_reverse(Ownership::matching($subjects, Ownership::rulesFor($project->id)));

        $found = [];

        foreach ($matches as $rule) {
            foreach ($this->owners($rule, $organization) as $assignee) {
                // Über Regelgrenzen hinweg entdoppelt: dieselbe Person steht in
                // einer allgemeinen und in einer engeren Regel, und zweimal
                // derselbe Eintrag im Dialog sieht nach einem Fehler aus.
                $found[$assignee->term()] ??= $assignee;
            }
        }

        return array_values($found);
    }

    /**
     * Die Zuständigen einer einzelnen Regel, soweit sie sich noch auflösen
     * lassen.
     *
     * @return list<IssueAssignee>
     */
    public function owners(OwnershipRule $rule, Organization $organization): array
    {
        $found = [];

        foreach ($rule->owners as $term) {
            if (! is_string($term)) {
                continue;
            }

            // Ohne Betrachter: `me` bezeichnet in einer Regel niemanden — wer
            // eine Regel schreibt, ist nicht der, für den sie gilt. Das
            // Formular weist den Text schon zurück; hier ist es die zweite
            // Hälfte derselben Aussage.
            $assignee = IssueAssignee::resolve($term, $organization);

            if ($assignee !== null) {
                $found[$assignee->term()] ??= $assignee;
            }
        }

        return array_values($found);
    }

    /**
     * Weist einen frisch entstandenen Fehler zu — wenn das Projekt es will und
     * eine Regel zutrifft.
     *
     * **Nur auf einen unbeanspruchten Eintrag.** Das ist die Zusage, die den
     * Schalter überhaupt vertretbar macht: eine automatische Zuweisung
     * überschreibt nie eine von Hand getroffene. Beim ersten Auftreten ist die
     * Prüfung noch nie falsch — der Eintrag ist Sekunden alt —, und sie steht
     * trotzdem da: sie kostet einen Vergleich und macht die Zusage zu einer
     * Eigenschaft dieses Weges statt zu einer Eigenschaft des Aufrufers.
     *
     * Der Handelnde ist **niemand**: im Verlauf steht die Zuweisung damit ohne
     * Namen, und die Oberfläche schreibt „automatisch" davor — dieselbe
     * Schreibweise wie bei allem anderen, was ohne Klick geschieht.
     */
    public function apply(Issue $issue, Event $event): ?IssueAssignee
    {
        $project = $issue->project;

        if (! $project instanceof Project || ! $project->ownership_auto_assign) {
            return null;
        }

        if ($issue->assigned_user_id !== null || $issue->assigned_team_id !== null) {
            return null;
        }

        $subjects = OwnershipSubjects::fromEvent($event);

        if ($subjects->isEmpty()) {
            return null;
        }

        $rule = Ownership::winner($subjects, Ownership::rulesFor($project->id));

        if ($rule === null) {
            return null;
        }

        $organization = $project->organization;

        if (! $organization instanceof Organization) {
            return null;
        }

        $owners = $this->owners($rule, $organization);

        if ($owners === []) {
            return null;
        }

        $assignee = $owners[0];

        $result = (new IssueActions)->assign(Issue::query()->whereKey($issue->id), $assignee);

        if ($result->count === 0) {
            return null;
        }

        // Benachrichtigt wird wie bei einer Zuweisung von Hand — über denselben
        // Weg und damit unter denselben persönlichen Einstellungen (A5). Ohne
        // Handelnden: „automatisch zugewiesen" ist die ehrliche Auskunft, und
        // niemand ist von der Meldung ausgenommen, weil niemand geklickt hat.
        $this->notifier->send($assignee, $result->count, $result->undoIds, null);

        return $assignee;
    }
}
