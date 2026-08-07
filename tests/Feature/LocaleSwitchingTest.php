<?php

namespace Tests\Feature;

use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Sprache hängt am Konto und wirkt überall: Oberfläche, Meldungen des
 * Frameworks und E-Mails. Ohne Wahl entscheidet der Browser.
 */
class LocaleSwitchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_interface_follows_the_language_of_the_account(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $response = $this->actingAs($user)->get('/profile')->assertOk();

        $props = self::propsOf($response);

        $this->assertSame('en', $props['locale']);
        $this->assertSame('en-US', $props['formats']['intl']);
        $this->assertSame('Sign out', $props['shell']['labels']['signOut']);
        $this->assertSame('Profile', $props['translations']['profile.title']);
    }

    public function test_without_a_choice_the_browser_decides(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $props = self::propsOf(
            $this->actingAs($user)
                ->get('/profile', ['Accept-Language' => 'en-US,en;q=0.9'])
                ->assertOk(),
        );

        $this->assertSame('en', $props['locale']);
    }

    /**
     * Fragt der Browser nach einer Sprache, die es nicht gibt, bleibt es bei
     * der Vorgabe der Anwendung — keine halb übersetzte Seite.
     */
    public function test_an_unsupported_browser_language_falls_back(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $props = self::propsOf(
            $this->actingAs($user)
                ->get('/profile', ['Accept-Language' => 'fr-FR,fr;q=0.9'])
                ->assertOk(),
        );

        $this->assertSame('de', $props['locale']);
        $this->assertSame('Profil', $props['translations']['profile.title']);
    }

    public function test_a_guest_gets_the_language_of_the_browser(): void
    {
        $english = self::propsOf(
            $this->get('/login', ['Accept-Language' => 'en-GB,en;q=0.9'])->assertOk(),
        );

        $this->assertSame('en', $english['locale']);

        $german = self::propsOf(
            $this->get('/login', ['Accept-Language' => 'de-AT,de;q=0.9,en;q=0.8'])->assertOk(),
        );

        $this->assertSame('de', $german['locale']);
    }

    public function test_the_stored_language_beats_the_browser(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $props = self::propsOf(
            $this->actingAs($user)
                ->get('/profile', ['Accept-Language' => 'en-US,en;q=0.9'])
                ->assertOk(),
        );

        $this->assertSame('de', $props['locale']);
    }

    public function test_the_language_can_be_chosen_in_the_profile(): void
    {
        $user = User::factory()->create(['locale' => null]);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => 'en',
            ])
            ->assertRedirect('/profile');

        $this->assertSame('en', $user->refresh()->locale);

        // Und wieder zurück auf „keine eigene Wahl": das leere Auswahlfeld
        // kommt als leerer Text an und darf nicht als Sprache landen.
        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => '',
            ])
            ->assertRedirect('/profile');

        $this->assertNull($user->refresh()->locale);
    }

    public function test_an_unknown_language_is_rejected(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => 'kl',
            ])
            ->assertSessionHasErrors('locale');

        $this->assertSame('de', $user->refresh()->locale);
    }

    public function test_framework_messages_follow_the_chosen_language(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [])
            ->assertSessionHasErrors([
                'password' => 'The password is required.',
            ], null, 'updatePassword');
    }

    /**
     * E-Mails entstehen im Job, nicht in der Anfrage — die Sprache kommt dort
     * vom Konto. Geprüft wird der gerenderte Text, weil erst dort der
     * Unterschied sichtbar wird.
     */
    public function test_emails_are_written_in_the_language_of_the_recipient(): void
    {
        Mail::fake();

        $inviter = User::factory()->create(['locale' => 'de']);
        $recipient = User::factory()->create(['locale' => 'en']);
        $organization = Organization::factory()->withMember($inviter)->create();

        $this->actingAs($inviter)
            ->from("/organisationen/{$organization->slug}")
            ->post("/organisationen/{$organization->slug}/einladungen", [
                'email' => $recipient->email,
                'role' => 'member',
            ])
            ->assertRedirect("/organisationen/{$organization->slug}");

        Mail::assertQueued(
            OrganizationInvitationMail::class,
            function (OrganizationInvitationMail $mail) use ($recipient): bool {
                return $mail->locale === 'en'
                    && $mail->hasTo($recipient->email)
                    && str_contains($mail->render(), 'Accept invitation');
            },
        );
    }

    /**
     * Die geteilten Inertia-Props der Antwort. Die Übersetzungstabelle liegt
     * flach (`profile.title` als ein Schlüssel), deshalb wird sie hier direkt
     * gelesen statt über die Punkt-Pfade von assertInertia — die würden den
     * Schlüssel als Verschachtelung deuten.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private static function propsOf(TestResponse $response): array
    {
        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var array<string, mixed> $props */
        $props = $page['props'];

        return $props;
    }
}
