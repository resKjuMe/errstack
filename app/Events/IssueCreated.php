<?php

namespace App\Events;

use App\Enums\QueueName;
use App\Models\Issue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ein Fehler ist zum **ersten Mal** aufgetreten — die Meldung, an der die
 * Fehlerliste erkennt, dass sie nicht mehr vollständig ist.
 *
 * **Nur beim ersten Mal, nicht bei jedem Auftreten.** Der Unterschied ist der
 * zwischen einer Handvoll Meldungen am Tag und einer je verarbeitetem Ereignis:
 * bei einem Ausfall wären das tausend Broadcasts in der Minute für denselben
 * Fehler, und die Oberfläche täte nichts anderes mehr, als eine Zahl
 * hochzuzählen, die niemand liest. Dass ein bekannter Fehler wieder aufgetreten
 * ist, steht in seinen Zählern; dass ein neuer da ist, steht nirgends.
 *
 * **Der Kanal hängt an der Organisation und nicht am Projekt.** Die Fehlerliste
 * zeigt in der Regel *alle* Projekte — ein Kanal je Projekt hieße, dass eine
 * Organisation mit fünfzig Projekten beim Öffnen der Seite fünfzig Abos und
 * fünfzig Berechtigungsanfragen auslöst. Es ist ein Abo, und welche Projekte
 * gerade gemeint sind, entscheidet die Ansicht anhand von `projectId`.
 *
 * Der Kanal ist **privat**: wer mitlesen darf, entscheidet routes/channels.php
 * anhand der Mitgliedschaft. Ein öffentlicher wäre auch mit leerer Nutzlast
 * falsch — „hier gibt es gerade einen neuen Fehler" ist selbst eine Auskunft.
 */
class IssueCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $issueId,
        public int $organizationId,
        public int $projectId,
        public string $category,
        public string $title,
        public string $level,
        public string $occurredAt,
    ) {}

    public static function fromIssue(Issue $issue): self
    {
        return new self(
            issueId: $issue->id,
            // Ein Nachschlag je **neuem** Eintrag, nicht je Ereignis: das ist der
            // Preis dafür, dass die Aufnahme die Organisation sonst nirgends
            // braucht — und er fällt genau so oft an wie diese Meldung selbst.
            organizationId: (int) $issue->project()->value('organization_id'),
            projectId: $issue->project_id,
            title: (string) ($issue->title ?? $issue->culprit ?? ''),
            level: $issue->level->value,
            occurredAt: $issue->first_seen->toIso8601String(),
        );
    }

    /**
     * Der Kanalname einer Organisation — an einer Stelle gebildet, damit Server,
     * Berechtigung und Oberfläche nicht drei Schreibweisen desselben Namens
     * pflegen.
     */
    public static function channelName(int $organizationId): string
    {
        return 'organizations.'.$organizationId.'.issues';
    }

    /**
     * Das Versenden läuft über die Warteschlange, und zwar auf
     * `notifications` — die Aufnahme darf nicht darauf warten, dass ein
     * Websocket-Server antwortet.
     */
    public function broadcastQueue(): string
    {
        return QueueName::Notifications->value;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channelName($this->organizationId));
    }

    public function broadcastAs(): string
    {
        return 'issue.created';
    }

    /**
     * @return array<string, string|int>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->issueId,
            // Die Ansicht filtert damit auf die Projekte, die gerade gewählt
            // sind — der Kanal trägt die ganze Organisation.
            'projectId' => $this->projectId,
            'title' => $this->title,
            'level' => $this->level,
            'occurredAt' => $this->occurredAt,
        ];
    }
}
