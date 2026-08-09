<?php

namespace App\Jobs;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Enums\OrganizationRole;
use App\Enums\QueueName;
use App\Enums\QuotaScope;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Quota;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Formats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Sagt der Verwaltung, dass ein Monatskontingent zur Neige geht.
 *
 * In der Warteschlange und nicht auf dem Weg der Meldung: ausgelöst wird die
 * Warnung von einer eingehenden Fehlermeldung, und die überwachte Anwendung
 * wartet auf deren Antwort, während bei ihr gerade etwas schiefläuft. Ein
 * langsamer Mailserver darf diese Antwort nicht aufhalten — und ausgerechnet
 * die Meldung, die die Schwelle reißt, dürfte sonst die langsamste des Monats
 * werden.
 *
 * Empfänger sind die, die etwas tun können: Eigentümer und Verwaltung der
 * Organisation. Ein Rundruf an alle Mitglieder wäre eine Nachricht über eine
 * Rechnung an Leute, die keine Kontingente ändern dürfen.
 */
class WarnAboutQuota implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $quotaId,
        public int $percent,
        public int $usage,
        public int $limit,
    ) {
        $this->onQueue(QueueName::Notifications->value);
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $quota = Quota::query()->find($this->quotaId);

        if ($quota === null || $quota->scope === QuotaScope::Key) {
            return;
        }

        $project = $quota->scope === QuotaScope::Project
            ? Project::query()->with('organization')->find($quota->scope_id)
            : null;

        $organization = $quota->scope === QuotaScope::Project
            ? $project?->organization
            : Organization::query()->find($quota->scope_id);

        if ($organization === null) {
            return;
        }

        $recipients = self::recipients($organization);

        if ($recipients->isEmpty()) {
            return;
        }

        $subject = $project->name ?? $organization->name;

        $dispatcher->sendToUsers(
            $recipients,
            new NotificationMessage(
                title: __('quotas.notification.title', [
                    'percent' => $this->percent,
                    'category' => $quota->category->label(),
                    'subject' => $subject,
                ]),
                // Der Satz nennt die Zahlen, nicht nur den Anteil: „80 %
                // verbraucht" beantwortet nicht, ob das viel ist. „412.000 von
                // 515.000" tut es.
                body: __('quotas.notification.body', [
                    'usage' => Formats::number($this->usage),
                    'limit' => Formats::number($this->limit),
                    'category' => $quota->category->label(),
                ]),
                // Bei 100 % kommt nichts mehr an — das ist eine Störung und
                // keine Randnotiz. Die Vorwarnung bei 80 % ist eine.
                level: $this->percent >= 100 ? NotificationLevel::Warning : NotificationLevel::Info,
                url: $project === null
                    ? route('organizations.quotas.index', $organization)
                    : route('projects.quotas.index', [$organization, $project]),
                context: [
                    __('quotas.notification.context_scope') => $quota->scope->label(),
                    __('quotas.notification.context_subject') => $subject,
                ],
                // Eine Kennung je Kontingent und Schwelle: die Warnung bei 80 %
                // und die bei 100 % sind zwei Nachrichten über dieselbe Sache,
                // aber nicht dieselbe Nachricht.
                reference: 'QUOTA-'.$quota->id.'-'.$this->percent,
            ),
            NotificationEventType::QuotaWarning,
            $project,
            $organization,
        );
    }

    /**
     * @return Collection<int, User>
     */
    private static function recipients(Organization $organization): Collection
    {
        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $organization->memberships()
                ->whereIn('role', [OrganizationRole::Owner->value, OrganizationRole::Admin->value])
                ->select('user_id'))
            ->get();

        return $users;
    }
}
