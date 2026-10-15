<?php

namespace App\Support\Integrations\Tickets\Linear;

use App\Enums\ExternalIssueState;
use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Support\Integrations\Tickets\RemoteTicket;
use App\Support\Integrations\Tickets\TicketContent;
use App\Support\Integrations\Tickets\TicketException;
use App\Support\Integrations\Tickets\TicketProvider;
use App\Support\Integrations\Tickets\TicketTarget;

/**
 * Linear an der gemeinsamen Ticket-Schnittstelle (X4).
 *
 * Linear sieht Jira ähnlich — bis auf drei Stellen, und alle drei sind hier
 * sichtbar:
 *
 * **1. Gearbeitet wird mit Kennungen, angezeigt werden Schlüssel.** `ENG-42` ist
 * die Anzeigeform; geändert wird über eine UUID. Deshalb steht sie an der
 * Verknüpfung (`external_id`) — ohne sie wäre für jedes Schließen erst ein
 * Nachschlagen nötig.
 *
 * **2. Der Zustand ist ein eigenes Objekt mit einer Art.** Linear kennt fünf
 * Arten (`triage`, `backlog`, `unstarted`, `started`, `completed`, `canceled`),
 * und je Team beliebig viele Zustände darin. Wie bei Jira wird auf die Art
 * gesehen und nicht auf den Namen: `completed` und `canceled` gelten als
 * geschlossen — ein abgebrochenes Ticket ist nicht erledigt im Sinne von
 * „behoben", aber sicher keines, an dem noch gearbeitet wird.
 *
 * **3. Es gibt keine Übergänge, sondern Zustände.** Geschlossen wird, indem ein
 * Zustand der passenden Art gesetzt wird. Welche das Team hat, muss geholt
 * werden — und danach wird der mit der niedrigsten Sortierposition genommen,
 * weil Teams mehrere haben („Erledigt", „Ausgeliefert").
 */
final readonly class LinearTicketProvider implements TicketProvider
{
    /**
     * Zustandsarten, die „nicht mehr offen" bedeuten.
     */
    private const CLOSED_TYPES = ['completed', 'canceled'];

    /**
     * Die Art, in die beim Wiederöffnen zurückgesetzt wird. `unstarted` und
     * nicht `backlog`: ein Fehler, der hier wieder aufgemacht wird, ist etwas,
     * das ansteht — ihn in die Ablage zu schieben wäre eine andere Aussage.
     */
    private const REOPEN_TYPE = 'unstarted';

    private LinearClient $client;

    public function __construct(private Integration $integration)
    {
        $this->client = new LinearClient($integration);
    }

    public function verify(): array
    {
        $viewer = $this->client->query('query { viewer { id name email } }');

        return [
            'account' => (string) (data_get($viewer, 'viewer.name') ?? data_get($viewer, 'viewer.email') ?? ''),
            'external_id' => (string) data_get($viewer, 'viewer.id', ''),
        ];
    }

    public function targets(): array
    {
        $data = $this->client->query('query { teams(first: 100) { nodes { id key name } } }');

        $nodes = data_get($data, 'teams.nodes');
        $targets = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            $key = (string) data_get($node, 'key', '');

            if ($key === '') {
                continue;
            }

            $targets[] = new TicketTarget(
                key: $key,
                name: (string) data_get($node, 'name', $key),
                externalId: (string) data_get($node, 'id', ''),
            );
        }

        return $targets;
    }

    public function create(Issue $issue, string $target): RemoteTicket
    {
        // Erst die Kennung des Teams: angelegt wird über sie, und der Schlüssel
        // aus der Auswahlliste ist nur das, was ein Mensch liest. Der Aufruf ist
        // zugleich die Prüfung, dass es das Team gibt — ein `teamId`, das Linear
        // nicht kennt, beantwortet die Änderung mit einem Fehler, aus dem nicht
        // hervorgeht, welches Feld gemeint war.
        $team = $this->team($target);

        $input = [
            'teamId' => $team->externalId,
            'title' => TicketContent::title($issue),
            'description' => TicketContent::markdown($issue),
        ];

        $priority = $this->integration->setting('default_priority');

        if ($priority !== null && is_numeric($priority)) {
            // Linear zählt Prioritäten (0 = keine, 1 = dringend … 4 = niedrig)
            // und nimmt keinen Namen. Ein nicht-numerischer Wert wird
            // stillschweigend übergangen statt den Aufruf scheitern zu lassen:
            // dieselbe Einstellung bedient beide Anbieter, und bei Jira steht
            // dort „High".
            $input['priority'] = (int) $priority;
        }

        $assignee = $this->integration->setting('default_assignee');

        if ($assignee !== null) {
            $input['assigneeId'] = $assignee;
        }

        $data = $this->client->query(<<<'GRAPHQL'
            mutation CreateIssue($input: IssueCreateInput!) {
                issueCreate(input: $input) {
                    success
                    issue { id identifier number title url state { type } team { key } }
                }
            }
            GRAPHQL, ['input' => $input]);

        $issueNode = data_get($data, 'issueCreate.issue');

        if (data_get($data, 'issueCreate.success') !== true || ! is_array($issueNode)) {
            // `success: false` ohne `errors` — Linear meldet so einen
            // abgelehnten Wunsch, den es nicht als Fehler führt. Ohne diese
            // Prüfung entstünde eine Verknüpfung auf ein Ticket, das es nicht
            // gibt.
            throw TicketException::failed(__('integrations.errors.not_created', [
                'provider' => __('enums.integration_provider.linear'),
            ]));
        }

        return self::ticket($issueNode);
    }

    public function find(string $target, int $number): RemoteTicket
    {
        // Über Team und Nummer und nicht über `issue(id: "ENG-42")`: die
        // Kurzform funktioniert, ist aber nicht zugesagt — sie hängt daran, dass
        // Linear den Schlüssel als Kennung durchgehen lässt. Der Filter ist die
        // Abfrage, die genau das ausdrückt, was gemeint ist.
        $data = $this->client->query(<<<'GRAPHQL'
            query FindIssue($key: String!, $number: Float!) {
                issues(filter: { team: { key: { eq: $key } }, number: { eq: $number } }, first: 1) {
                    nodes { id identifier number title url state { type } team { key } }
                }
            }
            GRAPHQL, ['key' => $target, 'number' => $number]);

        $node = data_get($data, 'issues.nodes.0');

        if (! is_array($node)) {
            throw TicketException::failed(__('integrations.errors.ticket_not_found', [
                'reference' => $target.'-'.$number,
            ]));
        }

        return self::ticket($node);
    }

    public function close(IssueLink $link): void
    {
        $this->move($link, self::CLOSED_TYPES);
    }

    public function reopen(IssueLink $link): void
    {
        $this->move($link, [self::REOPEN_TYPE]);
    }

    /**
     * Das Ticket in einen Zustand der gewünschten Art setzen.
     *
     * @param  list<string>  $types
     */
    private function move(IssueLink $link, array $types): void
    {
        $id = $link->external_id;

        if ($id === null || $id === '') {
            // Ohne Kennung ist nichts zu ändern. Der Fall entsteht bei
            // Verknüpfungen, die vor dieser Spalte entstanden sind — und bei
            // solchen, die von Hand in die Datenbank geschrieben wurden.
            throw TicketException::failed(__('integrations.errors.no_external_id', [
                'reference' => $link->reference(),
            ]));
        }

        $state = $this->stateOfType($link->repository, $types);

        $data = $this->client->query(<<<'GRAPHQL'
            mutation MoveIssue($id: String!, $stateId: String!) {
                issueUpdate(id: $id, input: { stateId: $stateId }) { success }
            }
            GRAPHQL, ['id' => $id, 'stateId' => $state]);

        if (data_get($data, 'issueUpdate.success') !== true) {
            throw TicketException::failed(__('integrations.errors.not_updated', [
                'reference' => $link->reference(),
            ]));
        }

        $this->integration->markSynced();
    }

    /**
     * Der Zustand des Teams, der zu einer der Arten passt.
     *
     * Der mit der niedrigsten Sortierposition, weil Teams mehrere haben
     * („Erledigt", „Ausgeliefert", „Abgenommen"). Die Position ist die
     * Reihenfolge, in der sie im Team stehen — und der erste ist der, den
     * Linear selbst als Standard anbietet.
     *
     * @param  list<string>  $types
     *
     * @throws TicketException
     */
    private function stateOfType(string $teamKey, array $types): string
    {
        $data = $this->client->query(<<<'GRAPHQL'
            query TeamStates($key: String!) {
                teams(filter: { key: { eq: $key } }, first: 1) {
                    nodes { states(first: 50) { nodes { id name type position } } }
                }
            }
            GRAPHQL, ['key' => $teamKey]);

        $nodes = data_get($data, 'teams.nodes.0.states.nodes');
        $candidates = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (in_array((string) data_get($node, 'type'), $types, true)) {
                $candidates[] = $node;
            }
        }

        if ($candidates === []) {
            throw TicketException::failed(__('integrations.errors.no_state', [
                'team' => $teamKey,
            ]));
        }

        usort($candidates, fn (array $a, array $b): int => (float) ($a['position'] ?? 0) <=> (float) ($b['position'] ?? 0));

        return (string) data_get($candidates[0], 'id', '');
    }

    /**
     * Das Team zu einem Schlüssel.
     *
     * @throws TicketException
     */
    private function team(string $key): TicketTarget
    {
        foreach ($this->targets() as $target) {
            if (strcasecmp($target->key, $key) === 0) {
                return $target;
            }
        }

        throw TicketException::failed(__('integrations.errors.unknown_target', ['target' => $key]));
    }

    /**
     * Aus einem Linear-Ticket das, was hier interessiert.
     *
     * @param  array<mixed>  $node
     */
    private static function ticket(array $node): RemoteTicket
    {
        return new RemoteTicket(
            project: (string) (data_get($node, 'team.key') ?? self::projectOf((string) data_get($node, 'identifier', ''))),
            number: (int) data_get($node, 'number', 0),
            title: (string) data_get($node, 'title', ''),
            url: (string) data_get($node, 'url', ''),
            state: self::state((string) data_get($node, 'state.type', '')),
            externalId: (string) data_get($node, 'id', '') ?: null,
        );
    }

    /**
     * Die Zustandsart, übersetzt in die beiden Fälle, die es hier gibt.
     */
    public static function state(?string $type): ExternalIssueState
    {
        return in_array((string) $type, self::CLOSED_TYPES, true)
            ? ExternalIssueState::Closed
            : ExternalIssueState::Open;
    }

    /**
     * `ENG-42` → `ENG`.
     */
    public static function projectOf(string $identifier): string
    {
        $position = strrpos($identifier, '-');

        return $position === false ? $identifier : substr($identifier, 0, $position);
    }
}
