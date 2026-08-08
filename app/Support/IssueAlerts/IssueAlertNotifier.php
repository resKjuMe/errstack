<?php

namespace App\Support\IssueAlerts;

use App\Enums\EventLevel;
use App\Enums\IssueAlertAction;
use App\Enums\IssueAlertCondition;
use App\Enums\NotificationEventType;
use App\Models\IssueAlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Formats;
use Illuminate\Support\Carbon;

/**
 * Was hinausgeht, wenn eine Regel greift.
 *
 * Die Entscheidung **ob** ist vorher gefallen ({@see IssueAlertEvaluator});
 * hier steht nur, **was** dann rausgeht und **an wen**. Der Weg führt über den
 * Versand aus A1 und nie daran vorbei — derselbe Schnitt wie bei den
 * Schwellwert-Alarmen (A3) und aus demselben Grund: ein eigener Versandweg für
 * Fehler-Alarme wäre eine zweite Stelle, an der jemand seine Ruhezeiten und
 * Abbestellungen einstellen müsste.
 */
final class IssueAlertNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * @param  list<IssueAlertCondition>  $matched
     * @return int Wie viele Zustellungen daraus entstanden sind.
     */
    public function send(IssueAlertRule $rule, IssueAlertContext $context, array $matched): int
    {
        $actions = $rule->parsedActions();

        if ($actions === []) {
            return 0;
        }

        $message = $this->message($rule, $context, $matched);
        $count = 0;

        foreach ($actions as $action) {
            $count += match ($action->type) {
                IssueAlertAction::Channel => $this->toChannels($context, $message, $action->channelId),
                IssueAlertAction::Members => $this->toMembers($context, $message),
            };
        }

        return $count;
    }

    /**
     * An die Kanäle der Organisation — an einen bestimmten oder an alle aktiven.
     *
     * Ein abgeschalteter oder inzwischen gelöschter Kanal ist kein Fehler,
     * sondern eine Regel, die ins Leere zeigt. Sie soll deshalb weiterlaufen und
     * ihre übrigen Aktionen ausführen; dass nichts ankam, steht am Verlauf
     * (`delivery_count`) — und genau danach sucht, wer eine Meldung vermisst.
     */
    private function toChannels(
        IssueAlertContext $context,
        NotificationMessage $message,
        ?int $channelId,
    ): int {
        $organization = $context->issue->project->organization;

        if ($channelId === null) {
            return $this->dispatcher->send($organization, $message)->count();
        }

        $channel = NotificationChannel::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->find($channelId);

        if ($channel === null) {
            return 0;
        }

        $this->dispatcher->sendTo($channel, $message);

        return 1;
    }

    /**
     * An die Mitglieder der Organisation, jedes nach seinen eigenen
     * Einstellungen (A5).
     *
     * Der Anlass ist {@see NotificationEventType::Alert} und damit kritisch: er
     * erreicht auch, wer pauschal abbestellt hat. Das ist die Zusage von A1 und
     * hier ausdrücklich gewollt — eine Alarmregel ist die Stelle, an der jemand
     * gesagt hat, dass er genau davon wissen will.
     */
    private function toMembers(IssueAlertContext $context, NotificationMessage $message): int
    {
        $project = $context->issue->project;

        $members = User::query()
            ->whereIn('id', $project->organization->memberships()->select('user_id'))
            ->get();

        $sent = $this->dispatcher->sendToUsers(
            $members,
            $message,
            NotificationEventType::Alert,
            $project,
            $project->organization,
        );

        return count(array_filter($sent, static fn (array $transports): bool => $transports !== []));
    }

    /**
     * @param  list<IssueAlertCondition>  $matched
     */
    private function message(IssueAlertRule $rule, IssueAlertContext $context, array $matched): NotificationMessage
    {
        $issue = $context->issue;
        $project = $issue->project;

        return new NotificationMessage(
            title: __('issue_alerts.notification.title', [
                'rule' => $rule->name,
                'project' => $project->name,
            ]),
            body: __('issue_alerts.notification.body', [
                'title' => $issue->title ?? __('issue_alerts.notification.untitled'),
                'reason' => $this->reasons($matched),
            ]),
            // Der Grad folgt dem Ereignis und nicht der Regel: dieselbe Regel
            // meldet je nach Fall eine Warnung und einen Absturz, und wer die
            // Nachricht sieht, will das unterscheiden können.
            level: $context->event->level->notificationLevel(),
            url: route('issues.show', $issue),
            context: $this->context($rule, $context, $matched),
            // Dieselbe Kennung über alle Meldungen einer Regel zu einem Fehler:
            // erst dadurch lassen sich Wiederholungen im Kanal einander
            // zuordnen.
            reference: 'ISSUE-'.$rule->id.'-'.$issue->id,
            occurredAt: Carbon::parse($context->occurredAt),
            // `fatal` ist der Grad, bei dem die Anwendung stehen geblieben ist
            // — eine solche Meldung wird nie gebündelt (A6), sondern geht
            // einzeln und sofort hinaus. Ein `error` dagegen schon: die
            // Fehlerwelle aus lauter gleichartigen Ausnahmen ist genau der
            // Fall, für den die Bündelung gebaut wurde.
            urgent: $context->event->level === EventLevel::Fatal,
        );
    }

    /**
     * @param  list<IssueAlertCondition>  $matched
     * @return array<string, string>
     */
    private function context(IssueAlertRule $rule, IssueAlertContext $context, array $matched): array
    {
        $issue = $context->issue;

        $values = [
            __('issue_alerts.notification.context_project') => $issue->project->name,
            __('issue_alerts.notification.context_rule') => $rule->name,
            __('issue_alerts.notification.context_reason') => $this->reasons($matched),
            __('issue_alerts.notification.context_level') => $context->event->level->label(),
            __('issue_alerts.notification.context_times_seen') => Formats::number($issue->times_seen, 0),
        ];

        if ($context->event->environment !== null) {
            $values[__('issue_alerts.notification.context_environment')] = $context->event->environment;
        }

        if ($context->event->release !== null) {
            $values[__('issue_alerts.notification.context_release')] = $context->event->release;
        }

        return $values;
    }

    /**
     * @param  list<IssueAlertCondition>  $matched
     */
    private function reasons(array $matched): string
    {
        return implode(', ', array_map(
            static fn (IssueAlertCondition $condition): string => $condition->label(),
            $matched,
        ));
    }
}
