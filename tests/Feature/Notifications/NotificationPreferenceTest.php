<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Enums\OrganizationRole;
use App\Enums\Platform;
use App\Jobs\DeliverPersonalNotification;
use App\Mail\PersonalNotificationMail;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPreferences;
use App\Notifications\PreferenceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_setting_the_default_of_the_event_applies(): void
    {
        $user = User::factory()->create();
        $preferences = $this->preferences();

        // Zuweisungen sind vorgabegemäß an, Deploys nicht — sonst wäre der
        // Posteingang nach dem ersten Auslieferungstag unbrauchbar.
        $this->assertTrue($preferences->wants($user, NotificationEventType::Assignment, NotificationTransport::Mail));
        $this->assertFalse($preferences->wants($user, NotificationEventType::Deploy, NotificationTransport::Mail));
    }

    public function test_a_finer_scope_beats_a_coarser_one(): void
    {
        [$user, $organization, $project] = $this->userWithProject();
        $preferences = $this->preferences();

        NotificationPreference::put($user, PreferenceScope::global(), NotificationEventType::Alert, NotificationTransport::Mail, false);
        NotificationPreference::put($user, PreferenceScope::forOrganization($organization), NotificationEventType::Alert, NotificationTransport::Mail, true);
        NotificationPreference::put($user, PreferenceScope::forProject($project), NotificationEventType::Alert, NotificationTransport::Mail, false);

        $this->assertFalse($preferences->wants($user, NotificationEventType::Alert, NotificationTransport::Mail));
        $this->assertTrue($preferences->wants($user, NotificationEventType::Alert, NotificationTransport::Mail, null, $organization));
        $this->assertFalse($preferences->wants($user, NotificationEventType::Alert, NotificationTransport::Mail, $project));
    }

    /**
     * Der Testfall aus der Aufgabenbeschreibung: Zuweisungs-Mails global aus,
     * Alarm-Mails für ein Projekt wieder an.
     */
    public function test_a_project_exception_overrides_a_global_switch_off(): void
    {
        [$user, , $project] = $this->userWithProject();
        $preferences = $this->preferences();

        NotificationPreference::put($user, PreferenceScope::global(), NotificationEventType::Assignment, NotificationTransport::Mail, false);
        NotificationPreference::put($user, PreferenceScope::global(), NotificationEventType::Alert, NotificationTransport::Mail, false);
        NotificationPreference::put($user, PreferenceScope::forProject($project), NotificationEventType::Alert, NotificationTransport::Mail, true);

        $this->assertFalse($preferences->allows($user, NotificationEventType::Assignment, NotificationTransport::Mail, $project));
        $this->assertTrue($preferences->allows($user, NotificationEventType::Alert, NotificationTransport::Mail, $project));
    }

    public function test_quiet_hours_hold_back_everything_but_critical_alerts(): void
    {
        $user = User::factory()->create();
        $user->ensureNotificationSetting()->update([
            'quiet_hours_enabled' => true,
            'quiet_from' => '22:00',
            'quiet_until' => '07:00',
            'timezone' => 'Europe/Berlin',
        ]);

        $preferences = $this->preferences();
        $night = Carbon::parse('2026-08-07 23:30', 'Europe/Berlin');
        $day = Carbon::parse('2026-08-07 09:00', 'Europe/Berlin');

        $this->assertFalse($preferences->allows($user, NotificationEventType::Assignment, NotificationTransport::Mail, null, null, $night));
        $this->assertTrue($preferences->allows($user, NotificationEventType::Alert, NotificationTransport::Mail, null, null, $night));
        $this->assertTrue($preferences->allows($user, NotificationEventType::Assignment, NotificationTransport::Mail, null, null, $day));
    }

    public function test_quiet_hours_are_read_in_the_time_zone_of_the_user(): void
    {
        $user = User::factory()->create();
        $user->ensureNotificationSetting()->update([
            'quiet_hours_enabled' => true,
            'quiet_from' => '22:00',
            'quiet_until' => '07:00',
            'timezone' => 'Pacific/Auckland',
        ]);

        // Mittags in Berlin ist es in Auckland tiefe Nacht.
        $noonInBerlin = Carbon::parse('2026-08-07 12:00', 'Europe/Berlin');

        $this->assertFalse($this->preferences()->allows(
            $user,
            NotificationEventType::Assignment,
            NotificationTransport::Mail,
            null,
            null,
            $noonInBerlin,
        ));
    }

    public function test_a_blanket_unsubscribe_leaves_critical_alerts_alone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/einstellungen/benachrichtigungen/eigene/abbestellen', ['unsubscribed' => true])
            ->assertSessionHasNoErrors();

        $preferences = $this->preferences();

        $this->assertFalse($preferences->allows($user->fresh(), NotificationEventType::Assignment, NotificationTransport::Mail));
        $this->assertTrue($preferences->allows($user->fresh(), NotificationEventType::Alert, NotificationTransport::Mail));
    }

    public function test_the_overview_shows_scopes_and_the_effective_state(): void
    {
        [$user, $organization, $project] = $this->userWithProject();

        $this->actingAs($user)
            ->get('/einstellungen/benachrichtigungen/eigene')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('notifications/Preferences')
                    ->has('scopes', 3)
                    ->where('scopes.0.key', 'global')
                    ->where('scopes.1.key', "organization:{$organization->id}")
                    ->where('scopes.2.key', "project:{$project->id}")
                    ->where('scopes.0.rows.deploy.mail.effective', false)
                    ->where('scopes.0.rows.assignment.mail.effective', true)
                    ->has('events', count(NotificationEventType::cases()))
            );
    }

    public function test_a_switched_off_alert_is_flagged_in_the_overview(): void
    {
        $user = User::factory()->create();

        foreach (NotificationTransport::cases() as $transport) {
            NotificationPreference::put($user, PreferenceScope::global(), NotificationEventType::Alert, $transport, false);
        }

        $this->actingAs($user)
            ->get('/einstellungen/benachrichtigungen/eigene')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('mutedCritical', 1)
                    ->where('mutedCritical.0.event', 'Alarme')
                    ->where('mutedCritical.0.scope', 'Überall')
            );
    }

    public function test_saving_stores_only_the_explicit_decisions(): void
    {
        [$user, $organization] = $this->userWithProject();

        $this->actingAs($user)
            ->put('/einstellungen/benachrichtigungen/eigene', [
                'scope' => "organization:{$organization->id}",
                'preferences' => [
                    'deploy' => ['mail' => 'on', 'in_app' => 'inherit'],
                    'assignment' => ['mail' => 'off'],
                ],
            ])
            ->assertSessionHasNoErrors();

        // „Erbt" legt bewusst keine Zeile an: sonst fröre die Vererbung auf dem
        // heutigen Stand ein.
        $this->assertDatabaseCount('notification_preferences', 2);
        $this->assertTrue($this->preferences()->wants($user, NotificationEventType::Deploy, NotificationTransport::Mail, null, $organization));
        $this->assertFalse($this->preferences()->wants($user, NotificationEventType::Assignment, NotificationTransport::Mail, null, $organization));
    }

    public function test_inherit_removes_an_earlier_decision(): void
    {
        [$user, $organization] = $this->userWithProject();
        NotificationPreference::put($user, PreferenceScope::forOrganization($organization), NotificationEventType::Deploy, NotificationTransport::Mail, true);

        $this->actingAs($user)
            ->put('/einstellungen/benachrichtigungen/eigene', [
                'scope' => "organization:{$organization->id}",
                'preferences' => ['deploy' => ['mail' => 'inherit']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_nobody_sets_preferences_for_a_foreign_project(): void
    {
        $outsider = User::factory()->create();
        $organization = Organization::factory()->withMember(User::factory()->create())->create();
        $project = Project::createFor($organization, 'Kasse', Platform::Php);

        $this->actingAs($outsider)
            ->put('/einstellungen/benachrichtigungen/eigene', [
                'scope' => "project:{$project->id}",
                'preferences' => ['alert' => ['mail' => 'off']],
            ])
            ->assertSessionHasErrors('scope');

        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_quiet_hours_need_a_span(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/einstellungen/benachrichtigungen/eigene/ruhezeiten', [
                'quiet_hours_enabled' => true,
                'quiet_from' => '22:00',
                'quiet_until' => '22:00',
                'timezone' => 'Europe/Berlin',
            ])
            ->assertSessionHasErrors('quiet_until');
    }

    public function test_the_dispatcher_asks_every_recipient_separately(): void
    {
        Queue::fake();

        [$wants, $organization, $project] = $this->userWithProject();
        $declines = User::factory()->create();
        $organization->setRole($declines, OrganizationRole::Member);

        NotificationPreference::put($declines, PreferenceScope::forProject($project), NotificationEventType::Alert, NotificationTransport::Mail, false);

        app(NotificationDispatcher::class)->sendToUsers(
            [$wants, $declines],
            new NotificationMessage('Kasse brennt', 'Und zwar lichterloh.'),
            NotificationEventType::Alert,
            $project,
        );

        Queue::assertPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->user->is($wants),
        );
        Queue::assertNotPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->user->is($declines),
        );
    }

    /**
     * Der Kern der Zusage „wirkt sofort": zwischen Einreihen und Zustellen
     * liegen Minuten, und in dieser Zeit gilt die neue Einstellung bereits.
     */
    public function test_a_queued_mail_is_dropped_when_the_setting_changed_meanwhile(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $job = new DeliverPersonalNotification(
            $user,
            new NotificationMessage('Dir zugewiesen', 'Ein Fehler wartet.'),
            NotificationEventType::Assignment,
        );

        NotificationPreference::put($user, PreferenceScope::global(), NotificationEventType::Assignment, NotificationTransport::Mail, false);

        $job->handle($this->preferences());

        Mail::assertNothingSent();
    }

    public function test_a_personal_mail_carries_an_unsubscribe_link(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        (new DeliverPersonalNotification(
            $user,
            new NotificationMessage('Dir zugewiesen', 'Ein Fehler wartet.'),
            NotificationEventType::Assignment,
        ))->handle($this->preferences());

        Mail::assertSent(PersonalNotificationMail::class, function (PersonalNotificationMail $mail) use ($user): bool {
            $mail->assertSeeInHtml('abbestellen', false);

            return $mail->hasTo($user->email);
        });
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function userWithProject(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, OrganizationRole::Admin)->create();
        $project = Project::createFor($organization, 'Kasse', Platform::Php);

        return [$user, $organization, $project];
    }

    private function preferences(): NotificationPreferences
    {
        // Frisch statt aus dem Container: der Zwischenspeicher des Singletons
        // trägt sonst den Stand von vor der letzten Änderung.
        return new NotificationPreferences;
    }
}
