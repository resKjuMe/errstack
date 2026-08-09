<?php

namespace App\Support\Integrations\GitHub;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Enums\IssueActivityType;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueLink;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Aus einem Fehler ein Ticket machen — oder ihn an ein vorhandenes hängen.
 *
 * Zwei Wege zum selben Ergebnis, und beide werden gebraucht: das neue Ticket
 * ist der Weg aus der Fehlerseite heraus („das muss jemand beheben"), das
 * vorhandene der Weg von der anderen Seite („daran wird schon gearbeitet, das
 * ist derselbe Fall"). Was danach in der Datenbank steht, ist dieselbe Zeile.
 *
 * **Der Text des neuen Tickets steht hier und nicht in einer Vorlage.** Er ist
 * kurz mit Absicht: Überschrift, Fehlerstelle, wie oft und seit wann — und der
 * Link zurück. Alles Weitere steht in der Anwendung, und ein Ticket, das den
 * halben Stacktrace mitschleppt, ist beim zweiten Lesen veraltet.
 */
final class GitHubIssueLinks
{
    /**
     * Ein neues Ticket anlegen und verknüpfen.
     *
     * @throws GitHubException
     */
    public static function create(Issue $issue, Integration $integration, string $repository, ?User $actor): IssueLink
    {
        $remote = (new GitHubClient($integration))->createIssue(
            $repository,
            self::title($issue),
            self::body($issue),
        );

        return self::store($issue, $integration, $repository, $remote, $actor, createdRemotely: true);
    }

    /**
     * Ein vorhandenes Ticket verknüpfen.
     *
     * Es wird nachgeschlagen, bevor die Zeile entsteht — eine Nummer, die es
     * drüben nicht gibt, soll nicht als Verknüpfung enden, die ins Leere zeigt.
     * Das kostet einen Aufruf und erspart die Rückfrage „warum ist der Link
     * kaputt".
     *
     * @throws GitHubException
     */
    public static function link(Issue $issue, Integration $integration, string $repository, int $number, ?User $actor): IssueLink
    {
        $remote = (new GitHubClient($integration))->issue($repository, $number);

        return self::store($issue, $integration, $repository, $remote, $actor, createdRemotely: false);
    }

    /**
     * @param  array{number: int, url: string, title: string, state: string}  $remote
     */
    private static function store(
        Issue $issue,
        Integration $integration,
        string $repository,
        array $remote,
        ?User $actor,
        bool $createdRemotely,
    ): IssueLink {
        // `updateOrCreate` und nicht `create`: derselbe Fehler zweimal mit
        // demselben Ticket zu verknüpfen ist kein Fehler, sondern ein zweiter
        // Klick — und der eindeutige Index würde ihn sonst als Ausnahme
        // beantworten.
        $link = IssueLink::query()->updateOrCreate(
            [
                'issue_id' => $issue->id,
                'provider' => IntegrationProvider::GitHub->value,
                'repository' => $repository,
                'number' => $remote['number'],
            ],
            [
                'integration_id' => $integration->id,
                'title' => $remote['title'],
                'url' => $remote['url'],
                'state' => ExternalIssueState::fromInput($remote['state']),
                'created_remotely' => $createdRemotely,
                'linked_by_id' => $actor?->id,
            ],
        );

        if ($link->wasRecentlyCreated) {
            self::note($issue, IssueActivityType::ExternalLinked, $actor, $link->reference());
        }

        $integration->markSynced();

        return $link;
    }

    /**
     * Die Verknüpfung wieder lösen.
     *
     * Das Ticket drüben bleibt stehen und wird auch nicht geschlossen: es kann
     * längst ein eigenes Leben führen, mit Kommentaren von Leuten, die von
     * dieser Anwendung nie gehört haben. Gelöst wird die Aussage über die
     * beiden, nicht eines von beiden.
     */
    public static function unlink(IssueLink $link, ?User $actor): void
    {
        $issue = $link->issue;
        $reference = $link->reference();

        $link->delete();

        if ($issue instanceof Issue) {
            self::note($issue, IssueActivityType::ExternalUnlinked, $actor, $reference);
        }
    }

    /**
     * Der Vermerk im Verlauf des Fehlers.
     *
     * Die Kennung steht als **Text** darin und nicht als Verweis auf die
     * Verknüpfung — dieselbe Entscheidung wie beim Namen des Zuständigen (S7):
     * ein Verlauf sagt, was damals galt, und eine gelöste Verknüpfung darf ihn
     * nicht in „verknüpft mit —" verwandeln.
     */
    private static function note(Issue $issue, IssueActivityType $type, ?User $actor, string $reference): void
    {
        IssueActivity::query()->create([
            'issue_id' => $issue->id,
            'project_id' => $issue->project_id,
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'type' => $type,
            'data' => ['reference' => $reference],
        ]);
    }

    /**
     * Die Überschrift des neuen Tickets.
     *
     * Titel und Fehlerstelle, wie auf der Fehlerseite — und gekürzt, weil
     * GitHub bei 256 Zeichen abschneidet und ein abgeschnittener Titel mitten
     * im Wort schlechter aussieht als ein bewusst gekürzter.
     */
    private static function title(Issue $issue): string
    {
        $title = trim((string) ($issue->title ?? $issue->culprit ?? ''));

        if ($title === '') {
            $title = __('issues.list.untitled');
        }

        return Str::limit($title, 200);
    }

    /**
     * Der Rumpf des neuen Tickets.
     *
     * Bewusst knapp: was zählt, ist der Link zurück. Alles, was hier als Text
     * landet, ist eine Kopie — und Kopien altern, während die Seite dahinter
     * aktuell bleibt.
     */
    private static function body(Issue $issue): string
    {
        $lines = [
            '**'.__('integrations.issue.body.culprit').':** '.($issue->culprit ?? '—'),
            '**'.__('integrations.issue.body.project').':** '.($issue->project?->name ?? '—'),
            '**'.__('integrations.issue.body.times_seen').':** '.$issue->times_seen,
            '**'.__('integrations.issue.body.first_seen').':** '.$issue->first_seen->toIso8601String(),
            '',
            __('integrations.issue.body.link', ['url' => self::issueUrl($issue)]),
        ];

        return implode("\n", $lines);
    }

    private static function issueUrl(Issue $issue): string
    {
        $organization = $issue->project?->organization;

        return $organization === null
            ? url('/')
            : route('issues.show', ['organization' => $organization, 'issue' => $issue]);
    }
}
