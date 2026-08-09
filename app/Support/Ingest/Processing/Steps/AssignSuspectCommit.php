<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Models\Event;
use App\Models\Issue;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Issues\IssueActions;
use App\Support\Issues\IssueAssignee;
use App\Support\Issues\IssueAssignmentNotifier;
use App\Support\Releases\SuspectCommits;
use Closure;

/**
 * Weist einen neuen Fehler dem Autor des verdächtigsten Commits zu (R4).
 *
 * Der zweite Teil der verdächtigen Commits: der erste zeigt sie nur an, dieser
 * handelt danach. Beides aus derselben Rechnung ({@see SuspectCommits}) — eine
 * zweite Herleitung würde bedeuten, dass die Oberfläche einen anderen
 * Verdächtigen nennt als den, dem der Fehler zugewiesen wurde.
 *
 * **Nur beim ersten Auftreten.** Danach nie wieder: ein Fehler, der seit
 * Wochen läuft, wurde entweder längst jemandem gegeben oder bewusst liegen
 * gelassen — beides ist eine Entscheidung, und eine automatische Zuweisung beim
 * tausendsten Ereignis würde sie überschreiben. Zugewiesen wird außerdem nur,
 * was **niemandem** gehört; eine bestehende Zuständigkeit ist unantastbar.
 *
 * **Nur mit Konto.** Ein Commit von jemandem, der hier nie ein Konto hatte,
 * bleibt in der Anzeige stehen und wird nicht zur Zuständigkeit: die gäbe es
 * dann für eine E-Mail-Adresse, die niemand liest.
 *
 * **Nur, wenn das Projekt es will** (`auto_assign_suspect_commits`, Vorgabe
 * aus). Anzeigen ist harmlos, Zuweisen nicht — es schreibt an einem Eintrag und
 * schickt eine Benachrichtigung, und ein Abgleich, der sich irren kann, tut das
 * dann an die falsche Person.
 *
 * **Er steht am Ende der Kette**, hinter dem Erfassen der Version (R1) und dem
 * Rückfall (S8): beide setzen die Auslieferungen, gegen die abgeglichen wird.
 * Davor stünde er vor seiner eigenen Voraussetzung.
 */
final class AssignSuspectCommit implements ProcessingStep
{
    public function __construct(
        private readonly IssueAssignmentNotifier $notifier,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $this->assign($context);

        $next($context);
    }

    private function assign(ProcessingContext $context): void
    {
        if ($context->get(AggregateIssue::WAS_NEW) !== true) {
            return;
        }

        $issue = $context->get(AggregateIssue::RESULT);
        $record = $context->get(NormalizeEvent::RESULT.'_record');

        if (! $issue instanceof Issue || ! $record instanceof Event) {
            return;
        }

        if ($issue->project?->auto_assign_suspect_commits !== true) {
            return;
        }

        // Frisch geholt und nicht aus dem Speicher: die Verweise auf die
        // Auslieferungen schreiben die Schritte davor mit einer eigenen
        // Anweisung in die Datenbank ({@see Issue::linkRelease()}), und die
        // Instanz hier weiß davon nichts. Ohne das Nachladen wäre die Liste der
        // betroffenen Auslieferungen leer — und der Abgleich damit immer
        // ergebnislos.
        $issue = $issue->fresh();

        if ($issue === null || $issue->assigned_user_id !== null || $issue->assigned_team_id !== null) {
            return;
        }

        // Die Organisation für den Link in der Benachrichtigung. Hier läuft kein
        // Aufruf, aus dem sie sich ergäbe (siehe ResolveOrganization) — die
        // Aufnahme arbeitet in der Warteschlange.
        $organization = $issue->project?->organization;

        if ($organization === null) {
            return;
        }

        foreach (SuspectCommits::forEvent($issue, $record) as $suspect) {
            $author = $suspect->authorId() === null ? null : $suspect->commit->author;

            if ($author === null) {
                continue;
            }

            $assignee = IssueAssignee::forUser($author);

            // Ohne Handelnden: zugewiesen hat der Abgleich und nicht eine
            // Person. Das ist zugleich der Grund, warum die Benachrichtigung
            // ankommt — sie unterbleibt nur bei dem, der selbst zugewiesen hat.
            (new IssueActions)->assign(Issue::query()->whereKey($issue->id), $assignee);

            $this->notifier->send($organization, $assignee, 1, [$issue->id], null);

            return;
        }
    }
}
