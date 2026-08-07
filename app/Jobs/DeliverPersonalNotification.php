<?php

namespace App\Jobs;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Enums\QueueName;
use App\Mail\PersonalNotificationMail;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Schickt eine Meldung an genau eine Person — sofern sie das im Moment des
 * Versands noch will.
 *
 * Genau darum wird die Erlaubnis hier ein zweites Mal geprüft und nicht nur
 * beim Einreihen: zwischen beidem liegen bei einer vollen Warteschlange
 * Minuten. Wer in dieser Zeit abbestellt, soll die Mail nicht mehr bekommen —
 * „wirkt sofort" heißt sonst „wirkt für die nächste Meldung".
 */
class DeliverPersonalNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
        public NotificationMessage $message,
        public NotificationEventType $event,
        public ?Project $project = null,
        public ?Organization $organization = null,
    ) {
        $this->onQueue(QueueName::Notifications->value);
    }

    public function handle(NotificationPreferences $preferences): void
    {
        // Frisch aus der Datenbank: das mitgereiste Modell trägt den Stand vom
        // Einreihen, und genau der könnte überholt sein.
        $user = $this->user->fresh();

        if ($user === null) {
            return;
        }

        if (! $preferences->allows($user, $this->event, NotificationTransport::Mail, $this->project, $this->organization)) {
            return;
        }

        Mail::to($user)->send(new PersonalNotificationMail(
            $this->message,
            $user,
            $this->event,
            $this->origin(),
        ));
    }

    /**
     * Woher die Meldung stammt — steht im Betreff und im Fußbereich. Das
     * Projekt ist die genauere Auskunft, die Organisation die nächstbeste.
     */
    private function origin(): string
    {
        return $this->project?->name
            ?? $this->organization?->name
            ?? (string) config('app.name', 'Errstack');
    }
}
