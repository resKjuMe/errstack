<?php

namespace App\Enums;

use App\Models\AuditLogEntry;
use App\Models\IssueActivity;
use App\Models\IssueLink;

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

    /**
     * Zurückgekommen: der erledigte Fehler ist wieder aufgetreten und hat sich
     * von selbst wieder geöffnet (S8).
     *
     * Getrennt von {@see Unresolved}, obwohl beide denselben Zustand
     * herstellen — für den, der den Verlauf liest, sind es zwei verschiedene
     * Vorgänge: das eine hat jemand entschieden, das andere ist geschehen. Wie
     * bei {@see IgnoreExpired} steht deshalb kein Name daneben, dafür die
     * Version, in der er zurückkam (`data`).
     */
    case Regressed = 'regressed';

    /** Stummgeschaltet — die Bedingung steht in `data`. */
    case Ignored = 'ignored';

    /**
     * Die Stummschaltung ist abgelaufen: die Bedingung ist eingetreten, und der
     * Eintrag meldet sich wieder.
     *
     * Er fällt bei der Aufnahme an, und dort steht niemand daneben — der
     * Vermerk trägt deshalb kein Konto. Dasselbe gilt für die Auslieferung und
     * die beiden Fälle aus S11 unten, die aus dem Hintergrund-Durchlauf kommen.
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

    /**
     * Der Fix ist draußen: der Eintrag stand auf „erledigt im nächsten Release",
     * und dieses Release wurde ausgeliefert (R3). Version und Umgebung stehen in
     * `data`.
     *
     * Ein eigener Fall und keine zweite Spielart von {@see self::Resolved}, aus
     * demselben Grund wie bei {@see self::IgnoreExpired}: erledigt hat jemand
     * von Hand, ausgeliefert wurde ohne Zutun. Für den, der den Verlauf liest,
     * sind das zwei Vorgänge — und der zweite ist der, auf den der erste
     * gewartet hat.
     */
    case Deployed = 'deployed';

    /**
     * Die Wichtigkeit hat sich geändert — von Hand oder durch die Ableitung
     * (S11).
     *
     * **Ein Fall für beide Urheber**, und das ist dieselbe Überlegung wie beim
     * Erledigen: „hoch" ist „hoch", gleich ob es jemand eingestellt oder die
     * Ableitung errechnet hat. Wer es war, steht ohnehin an jedem Vermerk
     * ({@see IssueActivity::$actor_name} — leer bei der Automatik), und die
     * Begründung, aus der die Ableitung ihre Stufe zieht, steht in `data`.
     * Genau dieses `data` ist die Zusage der Aufgabe: die Herleitung ist im
     * Verlauf nachvollziehbar, ohne dass sie ein zweites Mal gerechnet werden
     * müsste.
     */
    case PriorityChanged = 'priority';

    /**
     * Ein stummgeschalteter Eintrag ist aus dem Ruder gelaufen und deshalb
     * wieder offen (S11).
     *
     * Getrennt von {@see self::IgnoreExpired}, obwohl das Ergebnis dasselbe
     * ist: dort ist eine **vereinbarte** Bedingung eingetreten („bis 100
     * weitere"), hier hat niemand etwas vereinbart — der Fehler tritt weit
     * häufiger auf, als sein eigener Verlauf erwarten ließ. Für den, der die
     * Zeitleiste liest, sind das zwei verschiedene Auskünfte: die eine sagt
     * „wie bestellt", die andere „sieh hin".
     */
    case Escalated = 'escalated';

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

    /**
     * Mit einem Ticket beim Anbieter verknüpft (X1) — neu angelegt oder ein
     * vorhandenes aufgegriffen.
     *
     * **Ein Fall für beides**, obwohl das Anlegen mehr tut: für den, der den
     * Verlauf liest, ist das Ergebnis dasselbe — dieser Fehler hängt jetzt an
     * `acme/webshop#42`. Wo das Ticket herkam, steht am Verweis selbst
     * ({@see IssueLink::$created_remotely}) und ist eine Frage,
     * die niemand an den Verlauf stellt.
     *
     * Die Kennung steht als **Text** in `data` und nicht als Verweis auf die
     * Verknüpfung — dieselbe Entscheidung wie beim Namen des Zuständigen: eine
     * gelöste Verknüpfung darf den Vermerk nicht leerräumen.
     */
    case ExternalLinked = 'external_linked';

    /** Die Verknüpfung wurde wieder gelöst. Das Ticket drüben bleibt. */
    case ExternalUnlinked = 'external_unlinked';

    /**
     * Erledigt, weil das verknüpfte Ticket geschlossen wurde.
     *
     * Ein eigener Fall neben {@see self::Resolved} und aus demselben Grund wie
     * bei {@see self::Deployed}: erledigt hat dort jemand von Hand, hier ist es
     * geschehen — und zwar in einer anderen Anwendung. Wer den Verlauf liest,
     * soll sehen, dass hier niemand geklickt hat, sondern dass ein Ticket
     * zugemacht wurde.
     */
    case ExternalResolved = 'external_resolved';

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
