<?php

namespace App\Support\IssueAlerts;

use App\Enums\IssueStatus;
use App\Models\Event;
use App\Models\Issue;
use Carbon\CarbonImmutable;

/**
 * Der Anlass, den die Auswertung zu beurteilen hat: ein Ereignis, der Eintrag,
 * zu dem es gehört, und die beiden Feststellungen, die **nur** der Schritt
 * davor treffen konnte.
 *
 * `isNew` und `escalated` stehen hier, weil sie sich hinterher nicht mehr
 * ablesen lassen: „zum ersten Mal aufgetreten" ist eine Eigenschaft des
 * Augenblicks, in dem der Eintrag entstand, und „aufgewacht" hat die
 * Stummschaltung entschieden. Aus dem Eintrag allein wären beide nur zu raten.
 */
final readonly class IssueAlertContext
{
    public function __construct(
        public Issue $issue,
        public Event $event,
        public bool $isNew,
        public bool $escalated,
        public CarbonImmutable $occurredAt,
    ) {}

    /**
     * War der Eintrag erledigt, als dieses Ereignis eintraf?
     *
     * Der Rückfall, wie ihn diese Aufgabe sieht. Das Wiederaufmachen gehört zu
     * S8; solange es das nicht gibt, bleibt der Eintrag erledigt und würde ohne
     * die Marke im Regel-Zustand (App\Models\IssueAlertState) in jedem
     * Zeitfenster erneut als Rückfall gelten.
     */
    public function resolvedAt(): ?CarbonImmutable
    {
        return $this->issue->status === IssueStatus::Resolved ? $this->issue->resolved_at : null;
    }
}
