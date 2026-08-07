<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\UnsubscribeLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Der Abmelde-Link aus der Mail: ohne Anmeldung erreichbar, aber nur mit
 * gültiger Unterschrift, und wirksam erst auf ausdrücklichen Klick.
 */
class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_link_works_without_a_login(): void
    {
        $user = User::factory()->create();

        $this->get(UnsubscribeLink::for($user, NotificationEventType::Assignment))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('notifications/Unsubscribe')
                    ->where('recipient.email', $user->email)
                    ->where('event.value', 'assignment')
                    ->where('event.critical', false)
                    ->where('state.eventOff', false)
            );
    }

    public function test_a_link_without_a_signature_is_refused(): void
    {
        $user = User::factory()->create();

        $this->get("/benachrichtigungen/abmelden/{$user->id}/assignment")
            ->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Dieselbe Unterschrift, aber ein anderes Konto — genau der Versuch,
        // den die Unterschrift verhindern soll.
        $link = str_replace(
            "/abmelden/{$user->id}/",
            "/abmelden/{$other->id}/",
            UnsubscribeLink::for($user, NotificationEventType::Assignment),
        );

        $this->get($link)->assertForbidden();
    }

    /**
     * Vorschau-Funktionen und Virenscanner rufen Adressen aus Mails ungefragt
     * auf. Ein Aufruf allein darf deshalb nichts verändern.
     */
    public function test_merely_opening_the_link_changes_nothing(): void
    {
        $user = User::factory()->create();

        $this->get(UnsubscribeLink::for($user, NotificationEventType::Assignment))->assertOk();

        $this->assertDatabaseCount('notification_preferences', 0);
        $this->assertTrue((new NotificationPreferences)->wants(
            $user,
            NotificationEventType::Assignment,
            NotificationTransport::Mail,
        ));
    }

    public function test_unsubscribing_from_one_kind_switches_off_only_its_mails(): void
    {
        $user = User::factory()->create();
        $link = UnsubscribeLink::for($user, NotificationEventType::Assignment);

        $this->post($link, ['mode' => 'event'])->assertSessionHasNoErrors();

        $preferences = new NotificationPreferences;

        $this->assertFalse($preferences->wants($user, NotificationEventType::Assignment, NotificationTransport::Mail));
        // Das Postfach in der Anwendung bleibt unberührt, andere Anlässe auch.
        $this->assertTrue($preferences->wants($user, NotificationEventType::Assignment, NotificationTransport::InApp));
        $this->assertTrue($preferences->wants($user, NotificationEventType::Mention, NotificationTransport::Mail));
    }

    public function test_unsubscribing_from_everything_leaves_critical_alerts_alone(): void
    {
        $user = User::factory()->create();

        $this->post(UnsubscribeLink::for($user, NotificationEventType::Assignment), ['mode' => 'all'])
            ->assertSessionHasNoErrors();

        $preferences = new NotificationPreferences;
        $user->refresh();

        $this->assertFalse($preferences->allows($user, NotificationEventType::Mention, NotificationTransport::Mail));
        $this->assertTrue($preferences->allows($user, NotificationEventType::Alert, NotificationTransport::Mail));
    }

    /**
     * Ein kritischer Alarm hat keinen Ein-Klick-Ausschalter in der Mail: die
     * Seite verweist stattdessen in die Einstellungen.
     */
    public function test_a_critical_alert_is_shown_with_a_warning(): void
    {
        $user = User::factory()->create();

        $this->get(UnsubscribeLink::for($user, NotificationEventType::Alert))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('event.critical', true));
    }

    public function test_an_unknown_kind_leads_nowhere(): void
    {
        $user = User::factory()->create();

        $link = str_replace(
            '/assignment?',
            '/brieftaube?',
            UnsubscribeLink::for($user, NotificationEventType::Assignment),
        );

        // Die Unterschrift passt nicht mehr — das fällt schon vor dem
        // unbekannten Anlass auf.
        $this->get($link)->assertForbidden();
    }
}
