<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Meldungen aus dem Framework müssen dieselbe Sprache sprechen wie die
 * Oberfläche. Ohne diese Prüfung fällt eine Lücke erst im Betrieb auf, und zwar
 * als halb englische Seite.
 *
 * Die Konten tragen hier ausdrücklich `locale = 'de'`: seit O9 entscheidet ohne
 * eigene Wahl der Browser, und der Test-Client gibt sich als englischer aus.
 * Welche Sprache woher kommt, prüft {@see LocaleSwitchingTest}.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_german_is_the_default_of_the_application(): void
    {
        $this->assertSame('de', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
    }

    public function test_validation_messages_come_out_german(): void
    {
        $user = User::factory()->create(['locale' => 'de']);

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [])
            ->assertSessionHasErrors([
                'current_password' => 'Das aktuelle Passwort ist erforderlich.',
                'password' => 'Das Passwort ist erforderlich.',
            ], null, 'updatePassword');
    }

    public function test_the_rules_of_the_organization_forms_are_translated(): void
    {
        $user = User::factory()->create(['locale' => 'de']);
        $organization = Organization::factory()->withMember($user)->create();

        $this->actingAs($user)
            ->from("/organisationen/{$organization->slug}")
            ->post("/organisationen/{$organization->slug}/einladungen", [
                'email' => 'GROSS@example.com',
                'role' => 'chef',
            ])
            ->assertSessionHasErrors([
                'email' => 'Die E-Mail-Adresse darf nur Kleinbuchstaben enthalten.',
                'role' => 'Die Rolle ist keine gültige Auswahl.',
            ]);
    }

    public function test_the_error_pages_are_german(): void
    {
        $user = User::factory()->create(['locale' => 'de']);
        $foreign = Organization::factory()->create();

        // Kein Zugriff: die Meldung stammt aus der Autorisierung selbst.
        $this->actingAs($user)
            ->get("/organisationen/{$foreign->slug}")
            ->assertForbidden()
            ->assertSee('Dazu fehlt die Berechtigung.');

        $this->actingAs($user)
            ->get('/organisationen/gibt-es-nicht')
            ->assertNotFound()
            ->assertSee('Nicht gefunden');
    }
}
