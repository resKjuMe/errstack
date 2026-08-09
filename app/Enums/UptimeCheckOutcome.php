<?php

namespace App\Enums;

use App\Models\UptimeMonitor;

/**
 * Der Ausgang einer einzelnen Erreichbarkeits-Prüfung.
 *
 * Vier Arten zu scheitern, und die Unterscheidung ist keine Buchhaltung: sie
 * beantwortet die erste Rückfrage nach einer Meldung. „Nicht erreichbar" schickt
 * jemanden zum Netz, „HTTP 500" zur Anwendung, „Text fehlt" zur Auslieferung —
 * und eine Zeitüberschreitung heißt, dass die Gegenstelle lebt, aber steht.
 *
 * Ohne die Unterscheidung stünde in jeder Meldung dasselbe Wort, und der
 * Adressat begänne jedes Mal bei null.
 */
enum UptimeCheckOutcome: string
{
    /** Das Ziel hat wie erwartet geantwortet. */
    case Up = 'up';

    /** Keine Verbindung: Namensauflösung, Netz, Zertifikat. */
    case ConnectionFailed = 'connection_failed';

    /** Die Antwort kam nicht innerhalb der erlaubten Zeit. */
    case Timeout = 'timeout';

    /** Es kam eine Antwort, aber mit einem unerwarteten Statuscode. */
    case StatusMismatch = 'status_mismatch';

    /** Der Statuscode passte, der erwartete Text fehlte im Rumpf. */
    case ContentMismatch = 'content_mismatch';

    public function label(): string
    {
        return __('enums.uptime_check_outcome.'.$this->value);
    }

    public function isFailure(): bool
    {
        return $this !== self::Up;
    }

    /**
     * Der Zustand, den diese Prüfung für sich genommen bedeuten würde.
     *
     * „Für sich genommen", weil der Monitor darüber noch die Bestätigung und
     * die Schwelle legt ({@see UptimeMonitor::applyCheck()}) — hier
     * steht nur die Übersetzung von Messwert zu Zustand.
     */
    public function toStatus(): UptimeStatus
    {
        return $this === self::Up ? UptimeStatus::Up : UptimeStatus::Down;
    }
}
