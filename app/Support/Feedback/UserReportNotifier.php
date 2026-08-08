<?php

namespace App\Support\Feedback;

use App\Enums\NotificationEventType;
use App\Enums\NotificationLevel;
use App\Models\Project;
use App\Models\User;
use App\Models\UserReport;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Alerts\MetricAlertNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Was hinausgeht, wenn eine Rückmeldung eintrifft oder jemandem übergeben wird.
 *
 * Derselbe Schnitt wie bei den Alarmen ({@see MetricAlertNotifier}): der Weg
 * über {@see NotificationDispatcher} ist der einzige, den es geben darf.
 *
 * **Zwei Anlässe, zwei Empfängerkreise, und das ist Absicht.** Eine neue
 * Zuschrift geht an die Kanäle der Organisation — sie gehört noch niemandem, und
 * wer sie sich nimmt, entscheidet sich dort. Eine Übergabe geht an genau eine
 * Person, in ihren eigenen Posteingang: „das liegt jetzt bei dir" ist eine
 * Nachricht an sie und an sonst niemanden.
 *
 * Der Grad ist bewusst `Info` und nicht `Error`. Eine Rückmeldung ist kein
 * Ausfall — sie soll niemanden nachts wecken, und ein Kanal, der von ihr
 * genauso schrillt wie von einem Alarm, wird bald ganz abgeschaltet.
 */
final class UserReportNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Eine neue Rückmeldung ist eingetroffen.
     */
    public function send(UserReport $report): void
    {
        $project = $report->project;

        if ($project === null) {
            return;
        }

        $this->dispatcher->send($project->organization, new NotificationMessage(
            title: __('feedback.notification.title', ['project' => $project->name]),
            body: $this->body($report),
            level: NotificationLevel::Info,
            url: route('feedback.index', ['projects' => [$project->slug]]),
            context: $this->context($report, $project),
            reference: 'FEEDBACK-'.$report->id,
            occurredAt: Carbon::parse($report->received_at),
        ));
    }

    /**
     * Jemand hat die Rückmeldung übernommen — oder sie jemandem gegeben.
     */
    public function sendAssignment(UserReport $report, User $assignee): void
    {
        $project = $report->project;

        if ($project === null) {
            return;
        }

        $this->dispatcher->sendToUser(
            $assignee,
            new NotificationMessage(
                title: __('feedback.notification.assigned_title', ['project' => $project->name]),
                body: $this->body($report),
                level: NotificationLevel::Info,
                url: route('feedback.index', ['projects' => [$project->slug]]),
                context: $this->context($report, $project),
                reference: 'FEEDBACK-'.$report->id,
                occurredAt: Carbon::parse($report->received_at),
            ),
            NotificationEventType::Assignment,
            $project,
            $project->organization,
        );
    }

    /**
     * Der Text ist die Rückmeldung selbst, gekürzt.
     *
     * Nicht „es ist eine neue Rückmeldung eingegangen": wer das liest, muss
     * nachsehen, um zu wissen, ob es dringend ist. Die ersten zwei Sätze
     * beantworten das in der Benachrichtigung.
     */
    private function body(UserReport $report): string
    {
        return Str::limit($report->comments, 280);
    }

    /**
     * @return array<string, string>
     */
    private function context(UserReport $report, Project $project): array
    {
        $context = [
            __('feedback.notification.context_project') => $project->name,
            __('feedback.notification.context_kind') => $report->source()->label(),
        ];

        if ($report->name !== null) {
            $context[__('feedback.notification.context_name')] = $report->name;
        }

        if ($report->email !== null) {
            $context[__('feedback.notification.context_email')] = $report->email;
        }

        if ($report->url !== null) {
            $context[__('feedback.notification.context_url')] = $report->url;
        }

        return $context;
    }
}
