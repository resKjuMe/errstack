<?php

namespace App\Enums;

/**
 * Was geschieht, wenn eine Regel greift.
 *
 * Beide Wege führen über den Versand aus A1 und keiner an ihm vorbei: ein
 * eigener Versandweg für Fehler-Alarme wäre eine zweite Stelle, an der jemand
 * seine Ruhezeiten und Abbestellungen einstellen müsste.
 *
 * Die Aufgabe nennt außerdem Zuweisung und Ticket-Erstellung. Beide fehlen hier
 * bewusst: die Zuständigkeit entsteht erst mit S7, die Ticket-Systeme mit X1/X4.
 * Eine Aktion, die nichts tun kann, wäre schlimmer als keine — sie sähe im
 * Formular aus wie eine Zusage.
 */
enum IssueAlertAction: string
{
    /**
     * An die Benachrichtigungswege der Organisation — an einen bestimmten oder
     * an alle aktiven.
     */
    case Channel = 'channel';

    /**
     * An die Mitglieder der Organisation, jedes nach seinen persönlichen
     * Einstellungen (A5).
     */
    case Members = 'members';

    public function label(): string
    {
        return __('enums.issue_alert_action.'.$this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $action): array => [
            'value' => $action->value,
            'label' => $action->label(),
        ], self::cases());
    }
}
