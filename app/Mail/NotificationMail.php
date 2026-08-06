<?php

namespace App\Mail;

use App\Notifications\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Eine Meldung als E-Mail. Bewusst ohne `ShouldQueue`: verschickt wird sie aus
 * dem Zustell-Job heraus, der bereits in der Warteschlange läuft. Ein zweites
 * Einreihen würde das Ergebnis vom Protokoll abkoppeln.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public NotificationMessage $message,
        public string $organization,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->organization}] {$this->message->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notification',
            with: [
                'title' => $this->message->title,
                'body' => $this->message->body,
                'level' => $this->message->level->label(),
                'url' => $this->message->url,
                'context' => $this->message->context,
                'reference' => $this->message->reference,
                'organization' => $this->organization,
            ],
        );
    }
}
