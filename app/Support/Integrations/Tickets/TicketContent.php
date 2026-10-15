<?php

namespace App\Support\Integrations\Tickets;

use App\Models\Issue;
use App\Support\Integrations\GitHub\GitHubIssueLinks;
use Illuminate\Support\Str;

/**
 * Was in einem neu angelegten Ticket steht (X1, X4).
 *
 * **Der Text steht hier und nicht in einer Vorlage**, und er ist für alle
 * Anbieter derselbe: derselbe Fehler soll drüben nicht je nach Ticket-System
 * anders aussehen. Was sich unterscheidet, ist die Form, in die er gegossen
 * wird — GitHub und Linear nehmen Markdown, Jira ein Dokument aus Absätzen.
 * Deshalb gibt es hier beides: die Angaben einzeln ({@see fields()}) und
 * fertigen Markdown ({@see markdown()}).
 *
 * **Er ist kurz mit Absicht.** Überschrift, Fehlerstelle, wie oft und seit
 * wann — und der Link zurück. Alles Weitere steht in der Anwendung, und ein
 * Ticket, das den halben Stacktrace mitschleppt, ist beim zweiten Lesen
 * veraltet. Was hier als Text landet, ist eine Kopie, und Kopien altern,
 * während die Seite dahinter aktuell bleibt.
 *
 * Ausgelagert aus {@see GitHubIssueLinks}, wo dieser Text mit X1 entstanden ist:
 * mit dem zweiten und dritten Anbieter wäre er sonst dreimal dagewesen, und die
 * Erfahrung mit solchen Stellen ist, dass die drei Fassungen nach einem halben
 * Jahr drei verschiedene Texte sind.
 */
final class TicketContent
{
    /**
     * Die Überschrift.
     *
     * Gekürzt, weil GitHub bei 256 Zeichen abschneidet und Jira eine
     * Zusammenfassung über 255 Zeichen als Prüffehler zurückgibt — ein bewusst
     * gekürzter Titel ist besser als beides.
     */
    public static function title(Issue $issue): string
    {
        $title = trim((string) ($issue->title ?? $issue->culprit ?? ''));

        if ($title === '') {
            $title = __('issues.list.untitled');
        }

        return Str::limit($title, 200);
    }

    /**
     * Die Angaben, jeweils als Bezeichnung und Wert.
     *
     * Getrennt und nicht als fertiger Text, weil Jira daraus ein Dokument baut,
     * in dem die Bezeichnung fett und der Wert normal gesetzt ist — aus einer
     * Zeile `**Fehlerstelle:** …` wäre das nicht mehr zu gewinnen.
     *
     * @return array<string, string>
     */
    public static function fields(Issue $issue): array
    {
        return [
            __('integrations.issue.body.culprit') => (string) ($issue->culprit ?? '—'),
            __('integrations.issue.body.project') => $issue->project->name,
            __('integrations.issue.body.times_seen') => (string) $issue->times_seen,
            __('integrations.issue.body.first_seen') => $issue->first_seen->toIso8601String(),
        ];
    }

    /**
     * Der Satz mit dem Link zurück — die eine Zeile, um derentwillen der Rumpf
     * überhaupt geschrieben wird.
     */
    public static function link(Issue $issue): string
    {
        return __('integrations.issue.body.link', ['url' => self::url($issue)]);
    }

    /**
     * Der Rumpf als Markdown — für GitHub und Linear.
     */
    public static function markdown(Issue $issue): string
    {
        $lines = [];

        foreach (self::fields($issue) as $label => $value) {
            $lines[] = '**'.$label.':** '.$value;
        }

        $lines[] = '';
        $lines[] = self::link($issue);

        return implode("\n", $lines);
    }

    /**
     * Die Adresse des Fehlers hier.
     *
     * Ohne Organisation bleibt nur die Startseite: ein Fehler ohne Projekt gibt
     * es im Betrieb nicht, in einem Test schon — und ein Ticket mit einem Link
     * auf die Startseite ist besser als ein Aufruf, der an einer Ausnahme
     * scheitert.
     */
    private static function url(Issue $issue): string
    {
        $organization = $issue->project?->organization;

        return $organization === null
            ? url('/')
            : route('issues.show', ['organization' => $organization, 'issue' => $issue]);
    }
}
