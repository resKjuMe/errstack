<?php

namespace App\Support\Issues;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Http\Requests\IssueListRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Formats;

/**
 * Was hinausgeht, wenn jemandem ein Fehler zugewiesen wurde.
 *
 * Derselbe Schnitt wie bei den Nennungen ({@see IssueMentionNotifier}): der Weg
 * über {@see NotificationDispatcher} ist der einzige, den es geben darf — ob und
 * worüber jemand überhaupt etwas hören will, entscheiden seine persönlichen
 * Einstellungen (A5) und nicht die Stelle, die benachrichtigt.
 *
 * **Eine Nachricht je Zuweisung, nicht je Fehler.** Das ist die eine
 * Entscheidung, aus der sich der Rest ergibt. Eine Sammelaktion über 12.480
 * Einträge ist mit zwei Klicks ausgelöst; je Eintrag eine E-Mail wäre danach ein
 * Postfach, das niemand mehr öffnet, und ein Zustelldienst, der die Absenderin
 * sperrt. Bei genau einem Fehler steht sein Titel in der Nachricht, bei mehreren
 * ihre Anzahl und ein Link auf die Liste — die Auskunft, die man in dem Moment
 * braucht, ist dann ohnehin „wie viel ist das und wo finde ich es".
 *
 * **Wer selbst zuweist, hört nichts** — auch nicht, wenn er sich selbst zuweist
 * oder in dem Team steht, dem er zuweist. Eine Benachrichtigung über die eigene
 * Handlung sagt nichts, was der Handelnde nicht wüsste.
 */
final class IssueAssignmentNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * @param  list<int>  $issueIds  Die betroffenen Einträge, soweit bekannt.
     *                               Bei einer Sammelaktion über die ganze
     *                               Auswahl bleibt die Liste leer — dann zählt
     *                               nur `$count`.
     */
    public function send(IssueAssignee $assignee, int $count, array $issueIds, ?User $actor): void
    {
        if ($count < 1) {
            return;
        }

        $recipients = array_values(array_diff(
            $assignee->recipientIds(),
            array_filter([$actor?->id]),
        ));

        if ($recipients === []) {
            return;
        }

        $single = $count === 1 && count($issueIds) === 1
            ? Issue::query()->with('project.organization')->find($issueIds[0])
            : null;

        $project = $single?->project;

        $this->dispatcher->sendToUsers(
            User::query()->whereIn('id', $recipients)->get(),
            $this->message($assignee, $count, $single, $actor),
            NotificationEventType::Assignment,
            $project instanceof Project ? $project : null,
            $project?->organization,
        );
    }

    private function message(IssueAssignee $assignee, int $count, ?Issue $issue, ?User $actor): NotificationMessage
    {
        $actorName = $actor?->name ?? __('issues.activity.system');

        // An ein Team gerichtet steht das im Betreff: sonst liest ein Mitglied
        // „dir zugewiesen" und sucht vergeblich nach dem Grund, warum
        // ausgerechnet ihm.
        $titleKey = $assignee->team !== null
            ? 'issues.assignment.notification.title_team'
            : 'issues.assignment.notification.title';

        if ($issue !== null) {
            return new NotificationMessage(
                title: __($titleKey, [
                    'actor' => $actorName,
                    'assignee' => $assignee->label(),
                ]),
                // Der Fehler selbst und nicht „Ihnen wurde etwas zugewiesen":
                // wer das liest, muss sonst nachsehen, um zu wissen, ob es eilt.
                body: $issue->title ?? $issue->culprit ?? __('issues.list.untitled'),
                level: NotificationLevel::Info,
                url: route('issues.show', $issue),
                context: array_filter([
                    __('issues.assignment.notification.context_project') => $issue->project?->name,
                    __('issues.assignment.notification.context_culprit') => $issue->culprit,
                ]),
                reference: 'ISSUE-'.$issue->id,
                occurredAt: now(),
            );
        }

        return new NotificationMessage(
            title: __($titleKey, [
                'actor' => $actorName,
                'assignee' => $assignee->label(),
            ]),
            body: __('issues.assignment.notification.many', [
                'count' => Formats::number($count),
            ]),
            level: NotificationLevel::Info,
            // Der Link führt auf die Liste dessen, was gerade zugewiesen wurde —
            // und zwar über die Suchsprache, damit er das auch dann noch tut,
            // wenn die Liste inzwischen anders aussieht. Der Zustandsfilter geht
            // ausdrücklich auf „alle": zugewiesen wird auch, was erledigt ist.
            url: route('issues.index', [
                'q' => 'assigned:'.$assignee->term(),
                'status' => IssueListRequest::STATUS_ANY,
            ]),
            reference: null,
            occurredAt: now(),
        );
    }
}
