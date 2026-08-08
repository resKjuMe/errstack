<?php

namespace App\Mail;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationMessage;
use App\Notifications\UnsubscribeLink;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Mehrere Meldungen in einer Mail.
 *
 * Der Betreff nennt die **Anzahl** und das Projekt, nicht die erste Meldung:
 * wer in einer Fehlerwelle in den Posteingang sieht, will als Erstes das
 * Ausmaß erfassen. Im Rumpf steht dann jede Meldung mit ihrem Titel und ihrem
 * Link — die Sammelnachricht ist ein Wegweiser, keine Zusammenfassung, die
 * Einzelheiten unterschlägt.
 *
 * Wie die einzelne Meldung ohne `ShouldQueue`: verschickt wird sie aus
 * {@see App\Jobs\DeliverDigest} heraus, das bereits in der Warteschlange läuft.
 */
class DigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<NotificationMessage>  $messages
     */
    public function __construct(
        public array $messages,
        public User $recipient,
        public NotificationEventType $event,
        public Project $project,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('digests.mail.subject', [
                'count' => (string) count($this->messages),
                'project' => $this->project->name,
            ]),
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.digest',
            with: [
                'project' => $this->project->name,
                'count' => count($this->messages),
                'level' => $this->highestLevel()->label(),
                'items' => array_map(static fn (NotificationMessage $message): array => [
                    'title' => $message->title,
                    'body' => $message->body,
                    'url' => $message->url,
                    'context' => $message->context,
                ], $this->messages),
                'eventLabel' => $this->event->label(),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'settingsUrl' => route('notifications.preferences'),
            ],
        );
    }

    /**
     * Der schwerste Grad im Bündel. Eine Sammelnachricht, die zehn Warnungen
     * und einen Absturz enthält, ist eine Nachricht über einen Absturz — der
     * Durchschnitt wäre hier die unbrauchbarste aller Auskünfte.
     */
    private function highestLevel(): NotificationLevel
    {
        $order = [
            NotificationLevel::Info->value => 0,
            NotificationLevel::Warning->value => 1,
            NotificationLevel::Error->value => 2,
        ];

        $highest = NotificationLevel::Info;

        foreach ($this->messages as $message) {
            if ($order[$message->level->value] > $order[$highest->value]) {
                $highest = $message->level;
            }
        }

        return $highest;
    }

    private function unsubscribeUrl(): string
    {
        return UnsubscribeLink::for($this->recipient, $this->event);
    }
}
