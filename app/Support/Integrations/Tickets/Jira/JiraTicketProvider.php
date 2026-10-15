<?php

namespace App\Support\Integrations\Tickets\Jira;

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
 * Jira an der gemeinsamen Ticket-Schnittstelle (X4).
 *
 * Hier stehen die Fachfragen, die Jira anders beantwortet als jedes andere
 * System — und es sind genau zwei:
 *
 * **1. Was heißt „offen"?** Jira hat keine zwei Zustände, sondern so viele, wie
 * jemand im Arbeitsablauf angelegt hat („In Prüfung", „Wartet auf Kunde",
 * „Abgenommen"). Was es dazu gibt, ist die **Zustandskategorie**: `new`,
 * `indeterminate`, `done` — drei Werte, die Atlassian festlegt und die kein
 * Projekt umbenennen kann. Nur `done` gilt hier als geschlossen. Auf die Namen
 * zu sehen wäre die naheliegende Abkürzung und die Stelle, an der ein Projekt
 * mit deutschem Arbeitsablauf nie mehr einen Fehler erledigt.
 *
 * **2. Wie schließt man einen Vorgang?** Nicht, indem man ein Feld setzt: der
 * Zustand ist das Ergebnis eines **Übergangs**, und welche Übergänge es gibt,
 * entscheidet der Arbeitsablauf des Projekts. Deshalb werden sie erst geholt und
 * dann wird der gesucht, der in die Kategorie `done` führt. Gibt es keinen, ist
 * das kein Fehler dieser Anwendung, sondern eine Auskunft über den
 * Arbeitsablauf — und sie wird als solche gemeldet.
 */
final readonly class JiraTicketProvider implements TicketProvider
{
    /**
     * Die Kategorie, die „erledigt" bedeutet. Von Atlassian festgelegt.
     */
    private const DONE = 'done';

    /**
     * Der Vorgangstyp, wenn an der Anbindung keiner vorbelegt ist. `Task`, weil
     * es der einzige Typ ist, den jedes Jira-Projekt hat — `Bug` gibt es in
     * einem Projekt mit reinem Aufgaben-Schema nicht, und ein Anlegeversuch
     * damit scheitert an einem Prüffehler, den niemand erwartet.
     */
    public const DEFAULT_TYPE = 'Task';

    private JiraClient $client;

    public function __construct(private Integration $integration)
    {
        $this->client = new JiraClient($integration);
    }

    public function verify(): array
    {
        return $this->client->myself();
    }

    public function targets(): array
    {
        return array_values(array_map(
            fn (array $project): TicketTarget => new TicketTarget(
                key: $project['key'],
                name: $project['name'],
                externalId: $project['id'],
            ),
            array_filter($this->client->projects(), fn (array $project): bool => $project['key'] !== ''),
        ));
    }

    public function create(Issue $issue, string $target): RemoteTicket
    {
        $created = $this->client->createIssue($this->fields($issue, $target));

        if ($created['key'] === '') {
            throw TicketException::failed(__('integrations.errors.unexpected_response', [
                'provider' => __('enums.integration_provider.jira'),
            ]));
        }

        // Nachgeschlagen und nicht aus der Anlege-Antwort gebildet: die trägt
        // Kennung und Schlüssel und **keinen** Zustand — welcher der erste
        // Zustand des Arbeitsablaufs ist, weiß nur der Vorgang selbst. Ihn auf
        // „offen" zu setzen wäre in aller Regel richtig und in einem
        // Arbeitsablauf, der bei „Angenommen" beginnt, eine Behauptung.
        return $this->ticket($this->client->issue($created['key']), $created['id']);
    }

    public function find(string $target, int $number): RemoteTicket
    {
        return $this->ticket($this->client->issue($target.'-'.$number));
    }

    public function close(IssueLink $link): void
    {
        $this->move($link, done: true);
    }

    public function reopen(IssueLink $link): void
    {
        $this->move($link, done: false);
    }

    /**
     * Den Vorgang über den passenden Übergang bewegen.
     *
     * **Schon dort? Dann nichts tun.** Der Abgleich läuft in beide Richtungen,
     * und ein Vorgang, der ohnehin erledigt ist, soll keinen zweiten Eintrag in
     * seinem Verlauf bekommen — das ist derselbe Gedanke wie beim bedingten
     * `update` auf der Seite hier.
     */
    private function move(IssueLink $link, bool $done): void
    {
        $key = $link->reference();

        foreach ($this->client->transitions($key) as $transition) {
            $category = (string) data_get($transition, 'to.statusCategory.key');
            $id = (string) ($transition['id'] ?? '');

            if ($id === '') {
                continue;
            }

            if (($category === self::DONE) === $done) {
                $this->client->transition($key, $id);
                $this->integration->markSynced();

                return;
            }
        }

        // Kein passender Übergang. Das ist eine Auskunft über den Arbeitsablauf
        // des Projekts und keine Störung: aus „Abgenommen" führt oft kein Weg
        // zurück, und aus manchen Zuständen keiner nach vorn ohne ein Pflichtfeld.
        // Als Ausnahme, damit der Aufrufer entscheidet — die Warteschlange
        // vermerkt es, die Oberfläche zeigt es.
        throw TicketException::failed(__('integrations.errors.no_transition', [
            'reference' => $key,
        ]));
    }

    /**
     * Die Felder eines neuen Vorgangs — samt der Vorbelegung der Anbindung.
     *
     * Priorität und Zuständigkeit werden **nur** gesetzt, wenn sie hinterlegt
     * sind. Das ist keine Sparsamkeit: ein Projekt ohne Prioritätsfeld
     * beantwortet ein mitgeschicktes `priority` mit einem Prüffehler, und ein
     * leeres `assignee` ist bei Jira nicht „niemand", sondern ein ungültiger
     * Wert.
     *
     * @return array<string, mixed>
     */
    private function fields(Issue $issue, string $target): array
    {
        $fields = [
            'project' => ['key' => $target],
            'summary' => TicketContent::title($issue),
            'description' => self::document($issue),
            'issuetype' => ['name' => $this->integration->setting('default_type') ?? self::DEFAULT_TYPE],
        ];

        $priority = $this->integration->setting('default_priority');

        if ($priority !== null) {
            $fields['priority'] = ['name' => $priority];
        }

        $assignee = $this->integration->setting('default_assignee');

        if ($assignee !== null) {
            // Die Kennung des Jira-Kontos und nicht die des Kontos hier: es gibt
            // keine Zuordnung zwischen den beiden Nutzerverwaltungen, und eine
            // über die E-Mail-Adresse geratene wäre genau die Art Vermutung, die
            // im Betrieb einmal den Falschen benachrichtigt.
            $fields['assignee'] = ['id' => $assignee];
        }

        return $fields;
    }

    /**
     * Der Rumpf als Atlassian-Dokument.
     *
     * Jira nimmt in dieser Fassung der Schnittstelle kein Markdown, sondern ein
     * Dokument aus Knoten. Umgesetzt wird nur, was der Text braucht: Absätze,
     * fette Bezeichnungen, normaler Text. Ein vollständiger Markdown-Übersetzer
     * wäre hier fehl am Platz — der Text ist bekannt, er steht in
     * {@see TicketContent}.
     *
     * @return array<string, mixed>
     */
    private static function document(Issue $issue): array
    {
        $content = [];

        foreach (TicketContent::fields($issue) as $label => $value) {
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => $label.': ', 'marks' => [['type' => 'strong']]],
                    ['type' => 'text', 'text' => $value],
                ],
            ];
        }

        $content[] = [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => TicketContent::link($issue)]],
        ];

        return ['type' => 'doc', 'version' => 1, 'content' => $content];
    }

    /**
     * Aus einem Jira-Vorgang das, was hier interessiert.
     *
     * @param  array<mixed>  $issue
     */
    private function ticket(array $issue, ?string $externalId = null): RemoteTicket
    {
        $key = (string) ($issue['key'] ?? '');
        $externalId = ($externalId ?? '') !== '' ? $externalId : (string) ($issue['id'] ?? '');

        return new RemoteTicket(
            project: self::projectOf($key),
            number: self::numberOf($key),
            title: (string) data_get($issue, 'fields.summary', ''),
            url: $this->client->browseUrl($key),
            state: self::state($issue),
            externalId: $externalId !== '' ? $externalId : null,
        );
    }

    /**
     * Der Zustand — über die Kategorie, nicht über den Namen. Siehe den
     * Kommentar an der Klasse.
     *
     * @param  array<mixed>  $issue
     */
    public static function state(array $issue): ExternalIssueState
    {
        return data_get($issue, 'fields.status.statusCategory.key') === self::DONE
            ? ExternalIssueState::Closed
            : ExternalIssueState::Open;
    }

    /**
     * `OPS-42` → `OPS`.
     */
    public static function projectOf(string $key): string
    {
        $position = strrpos($key, '-');

        return $position === false ? $key : substr($key, 0, $position);
    }

    /**
     * `OPS-42` → `42`.
     */
    public static function numberOf(string $key): int
    {
        $position = strrpos($key, '-');

        return $position === false ? 0 : (int) substr($key, $position + 1);
    }
}
