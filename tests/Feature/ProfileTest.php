<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/einstellungen/konto/profil')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile')
                ->where('user.name', $user->name)
                ->where('user.email', $user->email)
                ->where('user.isUnverified', false)
            );
    }

    public function test_the_profile_page_stays_reachable_without_a_verified_address(): void
    {
        // Sonst käme man aus einem Tippfehler in der E-Mail-Adresse nicht heraus.
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/einstellungen/konto/profil')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('user.isUnverified', true));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/einstellungen/konto/profil', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/einstellungen/konto/profil');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/einstellungen/konto/profil', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/einstellungen/konto/profil');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/einstellungen/konto/profil', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/einstellungen/konto/profil')
            ->delete('/einstellungen/konto/profil', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/einstellungen/konto/profil');

        $this->assertNotNull($user->fresh());
    }
}
