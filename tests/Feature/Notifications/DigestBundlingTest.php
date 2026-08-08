<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Jobs\DeliverDigest;
use App\Jobs\DeliverPersonalNotification;
use App\Mail\DigestMail;
use App\Models\NotificationDigestEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPreferences;
use App\Support\Digests\DigestFlusher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DigestBundlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_window_every_notification_goes_out_on_its_own(): void
    {
        Queue::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 0);

        $this->send($user, $project, 'Erster');
        $this->send($user, $project, 'Zweiter');

        Queue::assertPushed(DeliverPersonalNotification::class, 2);
        $this->assertSame(0, NotificationDigestEntry::query()->count());
    }

    /**
     * Der Testfall aus der Aufgabenbeschreibung: Fenster auf fünf Minuten,
     * zehn Meldungen — es kommt eine Sammelnachricht statt zehn Einzel-Mails.
     */
    public function test_ten_notifications_within_the_window_become_one_digest(): void
    {
        Queue::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 5);

        for ($i = 1; $i <= 10; $i++) {
            $this->send($user, $project, "Fehler {$i}");
        }

        Queue::assertNotPushed(DeliverPersonalNotification::class);
        $this->assertSame(10, NotificationDigestEntry::query()->count());

        // Noch ist das Fenster offen — es passiert nichts.
        $this->assertSame(0, $this->flush(CarbonImmutable::now()->addMinutes(4)));
        $this->assertSame(10, NotificationDigestEntry::query()->count());

        $this->assertSame(1, $this->flush(CarbonImmutable::now()->addMinutes(6)));

        Queue::assertPushed(
            DeliverDigest::class,
            fn (DeliverDigest $job): bool => $job->user->is($user) && count($job->messages) === 10,
        );
        $this->assertSame(0, NotificationDigestEntry::query()->count());
    }

    /**
     * Die Zusage der Aufgabe: eine dringende Meldung wartet nie.
     */
    public function test_an_urgent_notification_is_never_bundled(): void
    {
        Queue::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 5);

        $this->send($user, $project, 'Alles steht', urgent: true);

        Queue::assertPushed(DeliverPersonalNotification::class, 1);
        $this->assertSame(0, NotificationDigestEntry::query()->count());
    }

    public function test_a_user_can_switch_bundling_off_for_themselves(): void
    {
        Queue::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 5);
        $user->ensureNotificationSetting()->update(['digest_enabled' => false]);

        $this->send($user, $project, 'Fehler');

        Queue::assertPushed(DeliverPersonalNotification::class, 1);
        $this->assertSame(0, NotificationDigestEntry::query()->count());
    }

    /**
     * Die Mindestanzahl entscheidet nicht über das Warten, sondern darüber, was
     * am Ende hinausgeht.
     */
    public function test_below_the_minimum_the_notifications_go_out_individually(): void
    {
        Queue::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 5, minEvents: 3);

        $this->send($user, $project, 'Einziger');
        $this->send($user, $project, 'Zweiter');

        $this->assertSame(1, $this->flush(CarbonImmutable::now()->addMinutes(6)));

        Queue::assertNotPushed(DeliverDigest::class);
        Queue::assertPushed(DeliverPersonalNotification::class, 2);
        $this->assertSame(0, NotificationDigestEntry::query()->count());
    }

    /**
     * Die Höchstanzahl schickt die Sammelnachricht los, bevor das Fenster
     * abgelaufen ist — ein Korb, der unbegrenzt wächst, ergibt am Ende eine
     * Mail, die niemand liest.
     */
    public function test_the_maximum_flushes_before_the_window_has_elapsed(): void
    {
        Queue::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 60, maxEvents: 3);

        for ($i = 1; $i <= 4; $i++) {
            $this->send($user, $project, "Fehler {$i}");
        }

        $this->assertSame(1, $this->flush(CarbonImmutable::now()));

        Queue::assertPushed(
            DeliverDigest::class,
            fn (DeliverDigest $job): bool => count($job->messages) === 3,
        );
        // Die vierte Meldung wartet weiter auf ihr Fenster.
        $this->assertSame(1, NotificationDigestEntry::query()->count());
    }

    public function test_the_digest_mail_names_the_count_and_lists_every_entry(): void
    {
        Mail::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 5);

        (new DeliverDigest($user, $project, NotificationEventType::Alert, [
            new NotificationMessage('Erster Fehler', 'Kaputt.'),
            new NotificationMessage('Zweiter Fehler', 'Auch kaputt.'),
        ]))->handle(new NotificationPreferences);

        Mail::assertSent(DigestMail::class, function (DigestMail $mail) use ($user): bool {
            $mail->assertSeeInHtml('Erster Fehler', false);
            $mail->assertSeeInHtml('Zweiter Fehler', false);
            // Über dieselbe Übersetzung geprüft und nicht gegen einen deutschen
            // Text: die Zusage ist „Anzahl und Projekt stehen im Betreff", und
            // die gilt in jeder Sprache.
            $mail->assertHasSubject(__('digests.mail.subject', ['count' => '2', 'project' => 'Kasse']));

            return $mail->hasTo($user->email);
        });
    }

    /**
     * Derselbe Kern wie bei der einzelnen Meldung: zwischen dem Einreihen und
     * dem Versand liegt hier sogar das ganze Fenster.
     */
    public function test_a_digest_is_dropped_when_the_recipient_unsubscribed_meanwhile(): void
    {
        Mail::fake();

        [$user, , $project] = $this->userWithProject(windowMinutes: 5);
        $job = new DeliverDigest($user, $project, NotificationEventType::Assignment, [
            new NotificationMessage('Erster', 'Text'),
            new NotificationMessage('Zweiter', 'Text'),
        ]);

        $setting = $user->ensureNotificationSetting();
        $setting->unsubscribed_at = Date::now();
        $setting->save();

        $job->handle(new NotificationPreferences);

        Mail::assertNothingSent();
    }

    private function send(User $user, Project $project, string $title, bool $urgent = false): void
    {
        app(NotificationDispatcher::class)->sendToUser(
            $user,
            new NotificationMessage(title: $title, body: 'Text', urgent: $urgent),
            NotificationEventType::Alert,
            $project,
            $project->organization,
        );
    }

    private function flush(CarbonImmutable $at): int
    {
        return (new DigestFlusher)->flush($at);
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function userWithProject(int $windowMinutes, int $minEvents = 2, int $maxEvents = 25): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Admin)->create();
        $project = Project::createFor($organization, 'Kasse', Platform::Php, [
            'digest_window_minutes' => $windowMinutes,
            'digest_min_events' => $minEvents,
            'digest_max_events' => $maxEvents,
        ]);

        return [$user, $organization, $project];
    }
}
