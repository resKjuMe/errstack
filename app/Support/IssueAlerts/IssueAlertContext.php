<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueStatus;
use App\Models\Event;
use App\Models\Issue;
use Carbon\CarbonImmutable;

/**
 * Der Anlass, den die Auswertung zu beurteilen hat: ein Ereignis, der Eintrag,
 * zu dem es gehört, und die drei Feststellungen, die **nur** die Schritte davor
 * treffen konnten.
 *
 * `isNew`, `escalated` und `regressedFrom` stehen hier, weil sie sich hinterher
 * nicht mehr ablesen lassen: „zum ersten Mal aufgetreten" ist eine Eigenschaft
 * des Augenblicks, in dem der Eintrag entstand, „aufgewacht" hat die
 * Stummschaltung entschieden, und „zurückgekommen" die Rückfallerkennung (S8) —
 * die den Eintrag dabei aufmacht und damit genau die Spur verwischt, an der es
 * abzulesen wäre. Aus dem Eintrag allein wären alle drei nur zu raten.
 */
final readonly class IssueAlertContext
{
    public function __construct(
        public Issue $issue,
        public Event $event,
        public bool $isNew,
        public bool $escalated,
        public CarbonImmutable $occurredAt,
        /**
         * Die Erledigung, die ein Rückfall in diesem Durchlauf beendet hat —
         * `null`, wenn es keinen gab.
         */
        public ?CarbonImmutable $regressedFrom = null,
    ) {}

    /**
     * War der Eintrag erledigt, als dieses Ereignis eintraf?
     *
     * Zwei Wege zu derselben Antwort, und der erste ist seit S8 der Regelfall:
     * hat die Rückfallerkennung den Eintrag gerade aufgemacht, steht die
     * Erledigung nur noch hier — am Eintrag ist sie weg.
     *
     * Der zweite Weg trägt, was sie **nicht** aufgemacht hat: eine Meldung aus
     * einer noch laufenden alten Fassung ist kein Grund, „erledigt in 1.4.2"
     * zu widerrufen, wohl aber einer, dem Zuständigen zu sagen, dass der
     * behobene Fehler weiter eintrifft.
     *
     * Der zurückgegebene Zeitpunkt ist zugleich die Marke, mit der derselbe
     * Rückfall nicht in jedem Zeitfenster erneut gemeldet wird
     * (App\Models\IssueAlertState).
     */
    public function resolvedAt(): ?CarbonImmutable
    {
        if ($this->regressedFrom !== null) {
            return $this->regressedFrom;
        }

        return $this->issue->status === IssueStatus::Resolved ? $this->issue->resolved_at : null;
    }
}
