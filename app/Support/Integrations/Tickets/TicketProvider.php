<?php

namespace App\Support\Integrations\Tickets;

use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueLink;

/**
 * Die gemeinsame Ticket-Schnittstelle (X4) — das Abnahmekriterium in einer
 * Datei.
 *
 * Sechs Aufrufe, und die Auswahl ist die eigentliche Arbeit an dieser Aufgabe:
 * **das ist alles, was ein Ticket-System können muss, damit ein Fehler hier
 * einen Vorgang drüben bekommt und beide voneinander wissen.** Jira kann
 * hundertmal mehr, Linear auch; nichts davon steht hier, und deshalb passen
 * beide hinein.
 *
 *   verify    wem der Zugang gehört — der Aufruf direkt nach dem Verbinden
 *   targets   wohin ein Ticket gelegt werden kann (Jira: Projekte, Linear: Teams)
 *   create    aus einem Fehler ein neues Ticket machen
 *   find      ein vorhandenes nachschlagen, bevor darauf verwiesen wird
 *   close     das Ticket erledigen, weil der Fehler hier erledigt wurde
 *   reopen    und der Rückweg
 *
 * **Was nicht hier steht: Wiederholungen und Statusabgleich.** Ein gescheiterter
 * Aufruf fliegt als {@see TicketException} heraus, und wer ihn ausgelöst hat,
 * entscheidet, was das bedeutet — die Warteschlange wiederholt einen Auftrag,
 * eine Anfrage aus der Oberfläche zeigt eine Meldung. Und ob überhaupt
 * abgeglichen wird, ist eine Einstellung an der Anbindung
 * ({@see Integration::syncsOutbound()}) und keine Frage an den
 * Anbieter.
 *
 * `close` und `reopen` nehmen die **Verknüpfung** und nicht eine Kennung: sie
 * brauchen je Anbieter Verschiedenes (Jira den Schlüssel für den Übergang,
 * Linear die UUID und die Zustandsliste des Teams), und das steht alles an der
 * Zeile. Ein Aufruf mit fünf Zeichenketten wäre die Alternative — und die
 * Stelle, an der beim nächsten Anbieter eine sechste dazukommt.
 */
interface TicketProvider
{
    /**
     * Wem der Zugang gehört. Der Aufruf, mit dem das Verbinden steht oder fällt:
     * ein Token, das hier nicht antwortet, wird nicht gespeichert.
     *
     * @return array{account: string, external_id: string}
     *
     * @throws TicketException
     */
    public function verify(): array;

    /**
     * Wohin ein Ticket gelegt werden kann.
     *
     * Wird **auf Anforderung** geholt und nicht mit einer Seite mitgeliefert:
     * es ist ein Aufruf über das Netz, und die Fehlerseite soll auch dann laden,
     * wenn Jira gerade nicht antwortet.
     *
     * @return list<TicketTarget>
     *
     * @throws TicketException
     */
    public function targets(): array;

    /**
     * Ein neues Ticket aus diesem Fehler.
     *
     * Der Text kommt aus {@see TicketContent} und ist für beide Anbieter
     * derselbe; was sich unterscheidet, ist die Form, in die er gegossen wird
     * (Jira erwartet ein Dokument, Linear Markdown).
     *
     * @param  string  $target  Schlüssel des Projekts bzw. Teams
     *
     * @throws TicketException
     */
    public function create(Issue $issue, string $target): RemoteTicket;

    /**
     * Ein vorhandenes Ticket nachschlagen.
     *
     * Der Aufruf **ist** die Prüfung: eine Nummer, die es drüben nicht gibt,
     * soll nicht als Verknüpfung enden, die ins Leere zeigt.
     *
     * @throws TicketException
     */
    public function find(string $target, int $number): RemoteTicket;

    /**
     * Das Ticket erledigen.
     *
     * @throws TicketException
     */
    public function close(IssueLink $link): void;

    /**
     * Das Ticket wieder öffnen.
     *
     * @throws TicketException
     */
    public function reopen(IssueLink $link): void;
}
