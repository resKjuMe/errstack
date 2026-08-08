<?php

namespace App\Jobs;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Enums\QueueName;
use App\Mail\DigestMail;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Schickt eine Sammelnachricht an genau eine Person — sofern sie sie im Moment
 * des Versands noch will.
 *
 * Dieselbe Prüfung wie bei der einzelnen Meldung ({@see DeliverPersonalNotification})
 * und aus demselben Grund, hier sogar mit deutlich mehr Abstand: zwischen der
 * ersten gebündelten Meldung und dieser Mail liegt das ganze Fenster. Wer in
 * dieser Zeit abbestellt hat, hat es vor dem Versand getan und nicht danach.
 */
class DeliverDigest implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<NotificationMessage>  $messages
     */
    public function __construct(
        public User $user,
        public Project $project,
        public NotificationEventType $event,
        public array $messages,
    ) {
        $this->onQueue(QueueName::Notifications->value);
    }

    public function handle(NotificationPreferences $preferences): void
    {
        $user = $this->user->fresh();

        if ($user === null || $this->messages === []) {
            return;
        }

        $organization = $this->project->organization;

        if (! $preferences->allows($user, $this->event, NotificationTransport::Mail, $this->project, $organization)) {
            return;
        }

        Mail::to($user)->send(new DigestMail(
            $this->messages,
            $user,
            $this->event,
            $this->project,
        ));
    }
}
