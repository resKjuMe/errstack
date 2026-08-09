<?php

namespace App\Support\Issues;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Models\Issue;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Formats;
use Illuminate\Support\Carbon;

/**
 * Was hinausgeht, wenn ein stummgeschalteter Fehler eskaliert ist (S11).
 *
 * **An Personen, nicht an die Kanäle der Organisation.** Eine Eskalation ist
 * die Rücknahme einer Entscheidung, die jemand getroffen hat — „ich will das
 * nicht mehr sehen" gilt nicht mehr. Diese Nachricht gehört deshalb an die,
 * die es angeht, und nicht in einen Alarm-Kanal, in dem sie neben echten
 * Ausfällen steht. Derselbe Schnitt wie bei den Nennungen
 * ({@see IssueMentionNotifier}) und aus demselben Grund.
 *
 * **Wer sie bekommt: die Abonnenten und der, der stummgeschaltet hat.** Die
 * Abonnenten, weil sie sich diesen Fehler ausdrücklich ans Ohr gebunden haben.
 * Und der Urheber der Stummschaltung, weil er die Aussage getroffen hat, die
 * gerade widerlegt wurde — ihn zu übergehen wäre die eine Lücke, die diese
 * ganze Meldung sinnlos machte. Er ist häufig ohnehin Abonnent; doppelt
 * benachrichtigt wird trotzdem niemand.
 *
 * **Der Grad ist `Warning` und nicht `Error`.** Ein eskalierter Fehler ist eine
 * Bitte hinzusehen und kein ausgelöster Alarm; ein Weg, der von beidem gleich
 * schrillt, wird bald ganz abgeschaltet.
 */
final class IssueEscalationNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function report(Issue $issue, IssueEscalation $escalation): void
    {
        $project = $issue->project;

        if ($project === null) {
            return;
        }

        $recipients = $this->recipients($issue);

        if ($recipients === []) {
            return;
        }

        $factor = $escalation->factor();

        $this->dispatcher->sendToUsers(
            User::query()->whereIn('id', $recipients)->get(),
            new NotificationMessage(
                title: __('issues.escalation.notification.title', ['project' => $project->name]),
                // Der Fehler selbst und die Zahl, die ihn zurückgeholt hat: wer
                // das liest, soll ohne einen Klick wissen, ob es eilt.
                body: __('issues.escalation.notification.body', [
                    'issue' => (string) ($issue->title ?? $issue->culprit ?? ''),
                    'observed' => Formats::number($escalation->observed),
                    'expected' => Formats::number((int) round($escalation->expected)),
                ]),
                level: NotificationLevel::Warning,
                // Ausserhalb einer Anfrage gebaut: die Organisation gehört
                // ausdrücklich dazu (siehe ResolveOrganization).
                url: route('issues.show', ['organization' => $project->organization, 'issue' => $issue]),
                context: array_filter([
                    __('issues.escalation.notification.context_project') => $project->name,
                    __('issues.escalation.notification.context_observed') => Formats::number($escalation->observed),
                    // Ohne Erwartungswert gibt es kein Vielfaches — dann steht
                    // die Zeile gar nicht erst da, statt „∞" zu behaupten.
                    __('issues.escalation.notification.context_factor') => $factor === null
                        ? null
                        : __('issues.escalation.factor', ['factor' => Formats::number($factor, 1)]),
                ], static fn (?string $value): bool => $value !== null),
                reference: 'ISSUE-'.$issue->id,
                // `escalated_at` ist unveränderlich abgelegt; die Nachricht
                // führt die vorhandene Fassung — umgewandelt statt umdeklariert.
                occurredAt: $issue->escalated_at === null ? null : Carbon::instance($issue->escalated_at),
            ),
            NotificationEventType::WorkflowChange,
            $project,
            $project->organization,
        );
    }

    /**
     * @return list<int>
     */
    private function recipients(Issue $issue): array
    {
        $ids = $issue->watcherIds();

        if ($issue->ignored_by_id !== null) {
            $ids[] = $issue->ignored_by_id;
        }

        return array_values(array_unique($ids));
    }
}
