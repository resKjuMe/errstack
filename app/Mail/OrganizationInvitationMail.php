<?php

namespace App\Mail;

use App\Enums\QueueName;
use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Einladung in eine Organisation. Geht an eine E-Mail-Adresse, die noch zu
 * keinem Konto gehören muss — der Link führt in beiden Fällen zur richtigen
 * Stelle (Anmeldung bzw. direkt zur Einladung).
 */
class OrganizationInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public OrganizationInvitation $invitation)
    {
        $this->onQueue(QueueName::Notifications->value);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Einladung zu {$this->invitation->organization->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.organization-invitation',
            with: [
                'organization' => $this->invitation->organization->name,
                'role' => $this->invitation->role->label(),
                'invitedBy' => $this->invitation->invitedBy?->name,
                'url' => $this->invitation->url(),
                'expiresAt' => $this->invitation->expires_at->format('d.m.Y'),
            ],
        );
    }
}
