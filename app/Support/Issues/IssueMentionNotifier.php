<?php

namespace App\Support\Issues;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Models\IssueComment;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Feedback\UserReportNotifier;
use Illuminate\Support\Str;

/**
 * Was hinausgeht, wenn jemand in einem Kommentar genannt wurde.
 *
 * Derselbe Schnitt wie bei den Rückmeldungen ({@see UserReportNotifier}): der
 * Weg über {@see NotificationDispatcher} ist der einzige, den es geben darf —
 * ob und worüber eine Person überhaupt etwas hören will, entscheiden ihre
 * persönlichen Einstellungen (A5) und nicht die Stelle, die benachrichtigt.
 *
 * **Eine Nennung geht an eine Person, nicht an die Kanäle der Organisation.**
 * „@Anna, kannst du dir das ansehen?" ist an Anna gerichtet; in einem
 * Slack-Kanal gelesen zu werden, macht daraus eine Frage an alle und an
 * niemanden.
 *
 * Der Grad ist `Info`. Eine Nennung ist keine Störung — sie soll niemanden
 * nachts wecken, und ein Weg, der von ihr genauso schrillt wie von einem
 * Alarm, wird bald ganz abgeschaltet.
 */
final class IssueMentionNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Benachrichtigt die genannten Personen — und die Mitglieder der genannten
     * Teams.
     *
     * **Der Schreibende bekommt nichts**, auch wenn er sich selbst nennt oder
     * in einem Team steht, das er nennt. Eine Benachrichtigung über die eigene
     * Nachricht sagt nichts, was der Absender nicht wüsste.
     *
     * @param  list<array{user_id: int|null, team_id: int|null, label: string}>  $mentions
     */
    public function send(IssueComment $comment, array $mentions, ?User $author): void
    {
        $recipients = $this->recipients($mentions, $author);

        if ($recipients === []) {
            return;
        }

        $issue = $comment->issue;
        $project = $issue?->project;

        if ($issue === null || $project === null) {
            return;
        }

        $this->dispatcher->sendToUsers(
            User::query()->whereIn('id', $recipients)->get(),
            new NotificationMessage(
                title: __('issues.comments.notification.title', [
                    'actor' => $comment->author_name ?? __('issues.activity.system'),
                    'project' => $project->name,
                ]),
                // Der Kommentar selbst und nicht „Sie wurden genannt": wer das
                // liest, muss sonst nachsehen, um zu wissen, ob es eilt.
                body: Str::limit($comment->body, 280),
                level: NotificationLevel::Info,
                // Ausserhalb einer Anfrage gebaut: die Organisation gehört
                // ausdrücklich dazu (siehe ResolveOrganization).
                url: route('issues.show', ['organization' => $project->organization, 'issue' => $issue]),
                context: [
                    __('issues.comments.notification.context_project') => $project->name,
                    __('issues.comments.notification.context_issue') => (string) ($issue->title ?? ''),
                ],
                reference: 'ISSUE-'.$issue->id,
                occurredAt: $comment->created_at,
            ),
            NotificationEventType::Mention,
            $project,
            $project->organization,
        );
    }

    /**
     * Die Kennungen der Konten, die etwas erfahren sollen.
     *
     * Ein Team wird hier zu seinen Mitgliedern aufgelöst und nicht beim
     * Auflösen der Nennung: im Kommentar steht „@Team Kasse", und das soll dort
     * auch dann noch stehen, wenn jemand das Team seither verlassen hat.
     * Benachrichtigt wird, wer **jetzt** drin ist.
     *
     * @param  list<array{user_id: int|null, team_id: int|null, label: string}>  $mentions
     * @return list<int>
     */
    private function recipients(array $mentions, ?User $author): array
    {
        $users = array_values(array_filter(array_map(
            static fn (array $mention): ?int => $mention['user_id'],
            $mentions,
        )));

        $teams = array_values(array_filter(array_map(
            static fn (array $mention): ?int => $mention['team_id'],
            $mentions,
        )));

        if ($teams !== []) {
            $members = Team::query()
                ->whereIn('teams.id', $teams)
                ->join('team_user', 'team_user.team_id', '=', 'teams.id')
                ->pluck('team_user.user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $users = array_merge($users, $members);
        }

        return array_values(array_diff(array_unique($users), array_filter([$author?->id])));
    }
}
