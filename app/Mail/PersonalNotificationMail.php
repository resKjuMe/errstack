<?php

namespace App\Mail;

use App\Enums\NotificationEventType;
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
 * Eine Meldung an eine einzelne Person — der Gegenpart zu NotificationMail,
 * die an die festen Verteiler einer Organisation geht.
 *
 * Der Unterschied ist der Abmelde-Link: er steht sichtbar im Fußbereich und
 * zusätzlich in der Kopfzeile `List-Unsubscribe`, damit ihn auch Mail-Programme
 * anbieten, die dafür einen eigenen Knopf haben.
 *
 * Wie NotificationMail bewusst ohne `ShouldQueue`: verschickt wird sie aus
 * DeliverPersonalNotification heraus, das bereits in der Warteschlange läuft.
 */
class PersonalNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public NotificationMessage $message,
        public User $recipient,
        public NotificationEventType $event,
        public string $origin,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->origin}] {$this->message->title}",
        );
    }

    /**
     * `List-Unsubscribe` ist die Kopfzeile, an der Mail-Programme ihren
     * Abmelden-Knopf festmachen. Bewusst ohne `List-Unsubscribe-Post`: das
     * versprochene Ein-Klick-Abmelden würde eine Anfrage ohne jede Rückfrage
     * verlangen, und die schickt kein Programm mit unserem Sitzungs-Token.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.personal-notification',
            with: [
                'title' => $this->message->title,
                'body' => $this->message->body,
                'level' => $this->message->level->label(),
                'url' => $this->message->url,
                'context' => $this->message->context,
                'reference' => $this->message->reference,
                'origin' => $this->origin,
                'eventLabel' => $this->event->label(),
                'isCritical' => $this->event->isCritical(),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'settingsUrl' => route('notifications.preferences'),
            ],
        );
    }

    private function unsubscribeUrl(): string
    {
        return UnsubscribeLink::for($this->recipient, $this->event);
    }
}
