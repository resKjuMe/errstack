<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Rahmen ist seit der Anmeldung (F3) nur angemeldet zu sehen — jeder Test,
     * der ihn prüft, braucht ein Konto.
     */
    private function signIn(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        return $user;
    }

    public function test_the_example_page_renders_in_the_shell(): void
    {
        $this->signIn();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('shell')
            );
    }

    public function test_the_components_page_renders_in_the_shell(): void
    {
        $this->signIn();

        $this->get('/bausteine')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Components'));
    }

    public function test_the_navigation_marks_the_current_page_as_active(): void
    {
        $this->signIn();

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.links.0.label', 'Übersicht')
                ->where('shell.links.0.active', true)
                ->where('shell.links.1.label', 'Organisationen')
                ->where('shell.links.1.active', false)
                ->where('shell.links.2.label', 'Bausteine')
                ->where('shell.links.2.active', false)
            );

        $this->get('/bausteine')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.links.0.active', false)
                ->where('shell.links.2.active', true)
            );
    }

    public function test_the_shell_knows_the_signed_in_user_and_the_account_menu(): void
    {
        $user = $this->signIn();

        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.user.name', $user->name)
                ->where('shell.user.email', $user->email)
                ->where('shell.logoutHref', route('logout'))
                ->where('shell.menu', fn (Collection $menu) => $menu->pluck('label')->all() === ['Profil', 'Zugriffstoken', 'Bausteine'])
            );
    }

    public function test_the_guest_state_carries_no_user(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.user', null)
                ->where('shell.loginHref', route('login'))
            );
    }

    public function test_the_root_view_applies_the_theme_before_rendering(): void
    {
        $this->signIn();

        // Anti-Flash-Script: setzt die .dark-Klasse synchron im <head>.
        $this->get('/')
            ->assertOk()
            ->assertSee("localStorage.getItem('theme')", false)
            ->assertSee("classList.toggle('dark'", false);
    }

    public function test_flash_messages_are_shared_with_every_page(): void
    {
        $this->signIn();

        $this->withSession(['status' => 'Gespeichert.'])
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.status', 'Gespeichert.'));
    }
}
