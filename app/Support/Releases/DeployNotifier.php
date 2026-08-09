<?php

namespace App\Support\Releases;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Models\Commit;
use App\Models\Deploy;
use App\Models\Release;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Formats;
use App\Support\Issues\IssueMentionNotifier;
use Illuminate\Support\Carbon;

/**
 * Was hinausgeht, wenn ausgeliefert wurde.
 *
 * **Benachrichtigt werden die Autoren der enthaltenen Commits** — und nur sie.
 * Das ist der Kreis, für den die Nachricht eine Handlungsaufforderung ist: wer
 * etwas in dieser Auslieferung hat, will wissen, dass es draußen ist, und ist
 * die Person, die nachsieht, wenn danach etwas kaputt ist. Ein Rundruf an alle
 * Mitglieder wäre bei zehn Auslieferungen am Tag die Sorte Meldung, nach der
 * jemand die Benachrichtigungen ganz abschaltet.
 *
 * Erreichbar sind davon die, deren Adresse sich einem Konto zuordnen ließ
 * (R2, `commits.author_id`). Die übrigen bleiben stehen, wo sie stehen: am
 * Commit, mit Name und Adresse. Eine Einladungs-Mail an eine fremde Adresse aus
 * einem Repository heraus wäre etwas anderes als eine Benachrichtigung.
 *
 * Der Weg über {@see NotificationDispatcher} ist der einzige, den es geben darf
 * — wie bei den Nennungen ({@see IssueMentionNotifier}): ob jemand überhaupt
 * etwas von Auslieferungen hören will, entscheiden seine persönlichen
 * Einstellungen (A5) und nicht die Stelle, die benachrichtigt. Der Anlass
 * {@see NotificationEventType::Deploy} ist dort **standardmäßig aus** für Mail
 * und an im Postfach der Anwendung.
 *
 * Der Grad ist `Info`: eine Auslieferung ist keine Störung. Ein Weg, der von
 * ihr genauso schrillt wie von einem Alarm, wird bald ganz abgeschaltet.
 */
final class DeployNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function send(Deploy $deploy): void
    {
        $release = $deploy->release;
        $project = $deploy->project;
        $environment = $deploy->environment;

        if ($release === null || $project === null || $environment === null) {
            return;
        }

        $recipients = self::recipients($release);

        if ($recipients === []) {
            return;
        }

        $commits = $release->commits()->count();

        $this->dispatcher->sendToUsers(
            User::query()->whereIn('id', $recipients)->get(),
            new NotificationMessage(
                title: __('releases.deploys.notification.title', [
                    'version' => $release->version,
                    'environment' => $environment->name,
                ]),
                // Warum diese Person es bekommt, steht im Text: „du hast etwas
                // darin". Ohne den Satz liest sich die Nachricht wie ein
                // Rundschreiben, und ein Rundschreiben wird abbestellt.
                body: __('releases.deploys.notification.body', [
                    'project' => $project->name,
                    'commits' => Formats::number($commits),
                ]),
                level: NotificationLevel::Info,
                url: route('releases.show', ['organization' => $project->organization, 'release' => $release]),
                context: [
                    __('releases.deploys.notification.context_project') => $project->name,
                    __('releases.deploys.notification.context_environment') => $environment->name,
                ],
                // Alle Nachrichten zu einer Auslieferung tragen dieselbe
                // Kennung: sie ist der Gegenstand, um den es geht.
                reference: 'DEPLOY-'.$deploy->id,
                // Umgewandelt, weil die Meldung einen veränderlichen Zeitpunkt
                // erwartet und der Deploy einen unveränderlichen führt.
                occurredAt: Carbon::instance($deploy->finished_at),
            ),
            NotificationEventType::Deploy,
            $project,
            $project->organization,
        );
    }

    /**
     * Die Konten der Commit-Autoren dieser Auslieferung.
     *
     * Eine Abfrage über die Verbindungstabelle statt der geladenen Beziehung:
     * eine Auslieferung nach längerer Pause hat Tausende Commits, und gebraucht
     * werden davon nur die verschiedenen Konten — die Commits selbst dafür zu
     * laden wäre die Sorte Abfrage, die im Testbestand harmlos aussieht.
     *
     * @return list<int>
     */
    private static function recipients(Release $release): array
    {
        return Commit::query()
            ->join('release_commit', 'release_commit.commit_id', '=', 'commits.id')
            ->where('release_commit.release_id', $release->id)
            ->whereNotNull('commits.author_id')
            ->distinct()
            ->pluck('commits.author_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
