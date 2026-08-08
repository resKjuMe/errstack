<?php

namespace App\Enums;

use App\Models\AuditLogEntry;
use App\Models\IssueActivity;

/**
 * Was an einem Fehler-Eintrag geschehen ist.
 *
 * Bewusst getrennt von {@see AuditAction}: das Änderungsprotokoll (O4)
 * beantwortet die Frage „wer hat an der Organisation etwas verstellt" und wird
 * von der Verwaltung gelesen; der Aktivitätsverlauf beantwortet „was ist mit
 * **diesem** Fehler passiert" und steht auf seiner Seite. Zusammengelegt wären
 * beide unbrauchbar — ein Protokoll, in dem zwischen zwei Rollenwechseln
 * fünfhundert stummgeschaltete Fehler stehen, liest niemand mehr, und ein
 * Fehler-Verlauf, der die halbe Organisation zeigt, sagt nichts über den Fehler.
 * Der Kommentar an {@see AuditLogEntry} sagt dasselbe von der anderen Seite.
 *
 * Der gespeicherte Wert ist `vorgang`, klein und ohne Punkt: anders als beim
 * Änderungsprotokoll gibt es hier nur einen Bereich.
 *
 * Die Einzelheiten — welche Version, welche Schwelle — stehen nicht im Wert,
 * sondern in `data` am {@see IssueActivity}. Ein eigener Fall je Spielart
 * („erledigt in Version") wäre die naheliegende Alternative und die falsche:
 * die Liste wüchse mit jeder Bedingung, und jede Auswertung, die „wie oft wurde
 * erledigt?" beantwortet, müsste sie alle kennen.
 */
enum IssueActivityType: string
{
    /** Erledigt — mit welcher Bedingung, steht in `data`. */
    case Resolved = 'resolved';

    /** Wieder geöffnet, von Hand. */
    case Unresolved = 'unresolved';

    /** Stummgeschaltet — die Bedingung steht in `data`. */
    case Ignored = 'ignored';

    /**
     * Die Stummschaltung ist abgelaufen: die Bedingung ist eingetreten, und der
     * Eintrag meldet sich wieder.
     *
     * Der einzige Fall, der ohne handelndes Konto entsteht — er fällt bei der
     * Aufnahme an, und dort steht niemand daneben.
     */
    case IgnoreExpired = 'ignore_expired';

    /**
     * Einer Person oder einem Team zugewiesen (S7).
     *
     * Wem, steht in `data` — als **Name** und nicht als Kennung. Ein Verlauf
     * wird gelesen, nicht ausgewertet: „Anna Beck zugewiesen" bleibt lesbar,
     * wenn das Konto gelöscht wurde, eine Kennung wäre dann eine Zahl ohne
     * Bedeutung. Dieselbe Entscheidung wie beim Namen des Handelnden.
     */
    case Assigned = 'assigned';

    /** Die Zuständigkeit wurde aufgehoben. */
    case Unassigned = 'unassigned';

    /** Gemerkt (Lesezeichen). */
    case Bookmarked = 'bookmarked';

    case Unbookmarked = 'unbookmarked';

    case Subscribed = 'subscribed';

    case Unsubscribed = 'unsubscribed';

    /**
     * Gelöscht, und künftige Meldungen desselben Fingerabdrucks werden
     * verworfen.
     *
     * Steht als Fall hier, obwohl der Eintrag selbst dabei verschwindet: der
     * Verlauf gehört zum Fehler, und dieser eine Vermerk gehört zum Projekt —
     * er ist der Beleg dafür, warum seither nichts mehr ankommt. Er wird
     * deshalb ohne Eintrag geschrieben ({@see IssueActivity::$issue_id} bleibt
     * leer) und bleibt im Projekt-Verlauf stehen.
     */
    case Discarded = 'discarded';

    /** Gelöscht, ohne künftige Meldungen zu verwerfen. */
    case Deleted = 'deleted';

    public function label(): string
    {
        return __('enums.issue_activity.'.$this->value);
    }

    /**
     * Überlebt dieser Vermerk den Eintrag, auf den er sich bezieht?
     *
     * Nur die beiden Löschungen: alles andere hängt an einem Eintrag, der noch
     * da ist, und würde ohne ihn niemandem etwas sagen.
     */
    public function outlivesIssue(): bool
    {
        return $this === self::Deleted || $this === self::Discarded;
    }
}
