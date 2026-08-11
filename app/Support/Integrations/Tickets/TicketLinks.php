<?php

namespace App\Support\Integrations\Tickets;

use App\Enums\IssueActivityType;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueLink;
use App\Models\User;
use App\Support\Integrations\GitHub\GitHubIssueLinks;

/**
 * Aus einem Fehler ein Ticket machen — oder ihn an ein vorhandenes hängen (X4).
 *
 * Das Gegenstück zu {@see GitHubIssueLinks}, für alles, was über die gemeinsame
 * Ticket-Schnittstelle läuft. Zwei Wege zum selben Ergebnis, und beide werden
 * gebraucht: das neue Ticket ist der Weg aus der Fehlerseite heraus („das muss
 * jemand beheben"), das vorhandene der Weg von der anderen Seite („daran wird
 * schon gearbeitet, das ist derselbe Fall"). Was danach in der Datenbank steht,
 * ist dieselbe Zeile — und zwar dieselbe Zeile wie bei GitHub.
 *
 * **Der Anbieter kommt von außen herein und wird hier nicht gewählt.** Diese
 * Klasse kennt Jira und Linear nicht; sie kennt {@see TicketProvider}. Das ist
 * der Punkt, an dem sich das Abnahmekriterium „auf einer gemeinsamen
 * Schnittstelle" einlöst: der dritte Anbieter braucht hier keine Zeile.
 */
final class TicketLinks
{
    /**
     * Ein neues Ticket anlegen und verknüpfen.
     *
     * @throws TicketException
     */
    public static function create(
        Issue $issue,
        Integration $integration,
        TicketProvider $provider,
        string $target,
        ?User $actor,
    ): IssueLink {
        return self::store(
            $issue,
            $integration,
            $provider->create($issue, $target),
            $actor,
            createdRemotely: true,
        );
    }

    /**
     * Ein vorhandenes Ticket verknüpfen.
     *
     * Es wird nachgeschlagen, bevor die Zeile entsteht — eine Nummer, die es
     * drüben nicht gibt, soll nicht als Verknüpfung enden, die ins Leere zeigt.
     * Das kostet einen Aufruf und erspart die Rückfrage „warum ist der Link
     * kaputt".
     *
     * @throws TicketException
     */
    public static function link(
        Issue $issue,
        Integration $integration,
        TicketProvider $provider,
        string $target,
        int $number,
        ?User $actor,
    ): IssueLink {
        return self::store(
            $issue,
            $integration,
            $provider->find($target, $number),
            $actor,
            createdRemotely: false,
        );
    }

    /**
     * Die Verknüpfung wieder lösen.
     *
     * Das Ticket drüben bleibt stehen und wird auch nicht geschlossen: es kann
     * längst ein eigenes Leben führen, mit Kommentaren von Leuten, die von
     * dieser Anwendung nie gehört haben. Gelöst wird die Aussage über die beiden,
     * nicht eines von beiden.
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

    private static function store(
        Issue $issue,
        Integration $integration,
        RemoteTicket $ticket,
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
                'provider' => $integration->provider->value,
                'repository' => $ticket->project,
                'number' => $ticket->number,
            ],
            [
                'integration_id' => $integration->id,
                'external_id' => $ticket->externalId,
                'title' => $ticket->title,
                'url' => $ticket->url,
                'state' => $ticket->state,
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
}
