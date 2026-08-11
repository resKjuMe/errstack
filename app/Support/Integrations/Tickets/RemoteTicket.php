<?php

namespace App\Support\Integrations\Tickets;

use App\Enums\ExternalIssueState;
use App\Models\IssueLink;

/**
 * Ein Ticket beim Anbieter, so weit es hier interessiert (X4).
 *
 * Fünf Angaben, und die Auswahl ist der Kern der gemeinsamen Schnittstelle:
 * **das ist alles, was aus einem Jira-Vorgang und einer Linear-Aufgabe
 * gleichermaßen herausfällt.** Alles darüber — Sprints, Story Points,
 * Beziehungen, Kommentare, die halbe Vorgangsverwaltung — bleibt drüben, und
 * zwar nicht aus Bequemlichkeit: was hier als Kopie liegt, altert, während die
 * Seite dahinter aktuell bleibt.
 *
 * `project` und `number` sind zusammen die **Anzeigeform** (`OPS-42`) und
 * gleichzeitig das, wonach eine eingehende Meldung sucht. `externalId` ist die
 * Kennung, unter der man drüben etwas **tut** — bei Linear eine UUID, bei Jira
 * die Vorgangs-Id. Die Trennung ist keine Doppelung: `OPS-42` ändert sich, wenn
 * jemand den Vorgang in ein anderes Projekt zieht, die Kennung nicht.
 */
final readonly class RemoteTicket
{
    public function __construct(
        /**
         * Der Schlüssel des Projekts bzw. Teams: `OPS`, `ENG`.
         */
        public string $project,
        /**
         * Die laufende Nummer darin — die `42` in `OPS-42`.
         */
        public int $number,
        public string $title,
        public string $url,
        public ExternalIssueState $state,
        public ?string $externalId = null,
    ) {}

    /**
     * Wie das Ticket genannt wird, wenn man es kurz nennen muss.
     *
     * Beide Anbieter schreiben es gleich (`OPS-42`), und das ist kein Zufall:
     * Linear hat die Schreibweise von Jira übernommen. Deshalb steht sie hier
     * und nicht je Anbieter — siehe {@see IssueLink::reference()},
     * das dieselbe Form aus der gespeicherten Zeile bildet.
     */
    public function reference(): string
    {
        return $this->project.'-'.$this->number;
    }
}
