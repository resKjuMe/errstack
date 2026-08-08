<?php

namespace App\Mail;

use App\Enums\NotificationEventType;
use App\Models\User;
use App\Notifications\UnsubscribeLink;
use App\Support\Formats;
use App\Support\Reports\WeeklyProjectReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Der Wochenbericht als E-Mail.
 *
 * Alle Zahlen werden **hier** geschrieben und nicht in der Vorlage: wie eine
 * Zahl aussieht, entscheidet die Sprache, und eine Mail hat keinen Browser, der
 * das nachholen könnte — derselbe Grund wie bei den Alarm-Meldungen.
 */
class WeeklyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WeeklyProjectReport $report,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('reports.weekly.subject', [
                'project' => $this->report->project->name,
                'week' => (string) Formats::date($this->report->start),
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
        $trend = $this->report->trendPercent();

        return new Content(
            markdown: 'mail.weekly-report',
            with: [
                'project' => $this->report->project->name,
                'from' => (string) Formats::date($this->report->start),
                'until' => (string) Formats::date($this->report->end->subDay()),
                'events' => Formats::number($this->report->events, 0),
                'newIssues' => Formats::number($this->report->newIssues, 0),
                'resolvedIssues' => Formats::number($this->report->resolvedIssues, 0),
                'trend' => $trend === null
                    ? __('reports.weekly.trend_unknown')
                    : __('reports.weekly.trend_value', [
                        'sign' => $trend > 0 ? '+' : '',
                        'percent' => Formats::number($trend, 1),
                    ]),
                'topIssues' => array_map(static fn (array $issue): array => [
                    'title' => $issue['title'],
                    'url' => $issue['url'],
                    'count' => Formats::number($issue['count'], 0),
                ], $this->report->topIssues),
                'topAreas' => array_map(static fn (array $area): array => [
                    'name' => $area['name'],
                    'count' => Formats::number($area['count'], 0),
                ], $this->report->topAreas),
                'projectUrl' => route('projects.show', [
                    $this->report->project->organization,
                    $this->report->project,
                ]),
                'eventLabel' => NotificationEventType::WeeklyDigest->label(),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'settingsUrl' => route('notifications.preferences'),
            ],
        );
    }

    private function unsubscribeUrl(): string
    {
        return UnsubscribeLink::for($this->recipient, NotificationEventType::WeeklyDigest);
    }
}
