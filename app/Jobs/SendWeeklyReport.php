<?php

namespace App\Jobs;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Enums\QueueName;
use App\Mail\WeeklyReportMail;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Support\Reports\WeeklyProjectReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Der Wochenbericht eines Projekts an alle, die ihn wollen.
 *
 * **Ein Job je Projekt und nicht je Empfänger.** Der Bericht ist für alle
 * derselbe; ihn je Mitglied neu zu rechnen wären zwanzig gleiche Abfragen für
 * zwanzig gleiche Mails. Was sich je Empfänger unterscheidet, ist allein die
 * Frage, ob er ihn bekommen will — und die ist billig.
 *
 * Der Bericht entsteht **hier** und nicht beim Einreihen: so trägt die
 * Warteschlange nur Projekt und Woche, und ein Job, der eine Stunde liegen
 * bleibt, rechnet trotzdem mit dem Stand seines Zeitraums und nicht mit einem
 * halb gerechneten Zwischenergebnis von vorhin.
 */
class SendWeeklyReport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Project $project,
        /** Beginn der berichteten Woche als `Y-m-d` — ein Datum überlebt die Warteschlange unbeschadet. */
        public string $weekStart,
    ) {
        $this->onQueue(QueueName::Notifications->value);
    }

    public function handle(NotificationPreferences $preferences): void
    {
        $report = WeeklyProjectReport::build($this->project, CarbonImmutable::parse($this->weekStart));

        if (! $report->hasActivity()) {
            return;
        }

        $organization = $this->project->organization;

        $members = User::query()
            ->whereIn('id', $organization->memberships()->select('user_id'))
            ->get();

        foreach ($members as $member) {
            // Dieselbe Frage wie bei jeder anderen Meldung, samt Ruhezeit: sie
            // gilt für alles, was nicht kritisch ist, und der Wochenbericht ist
            // das Gegenteil davon. Wessen Ruhezeit über der Versandzeit liegt,
            // bekommt ihn nicht — das ist die Entscheidung aus A5 und keine
            // eigene; eine zweite Stelle mit einer eigenen Meinung dazu wäre
            // genau die, die einem Abbestellten doch noch Post schickt.
            $allowed = $preferences->allows(
                $member,
                NotificationEventType::WeeklyDigest,
                NotificationTransport::Mail,
                $this->project,
                $organization,
            );

            if (! $allowed) {
                continue;
            }

            Mail::to($member)->send(new WeeklyReportMail($report, $member));
        }
    }
}
