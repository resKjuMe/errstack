<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ShellTest extends TestCase
{
    public function test_the_example_page_renders_in_the_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('shell')
            );
    }

    public function test_the_components_page_renders_in_the_shell(): void
    {
        $this->get('/bausteine')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Components'));
    }

    public function test_the_navigation_marks_the_current_page_as_active(): void
    {
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.links.0.label', 'Übersicht')
                ->where('shell.links.0.active', true)
                ->where('shell.links.1.label', 'Bausteine')
                ->where('shell.links.1.active', false)
            );

        $this->get('/bausteine')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.links.0.active', false)
                ->where('shell.links.1.active', true)
            );
    }

    public function test_menu_entries_without_a_route_are_left_out(): void
    {
        // Profil und Logout kommen erst mit der Anmeldung (Task F3). Bis dahin
        // darf die Shell keine toten Links zeigen.
        $this->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shell.user', null)
                ->where('shell.logoutHref', null)
                ->where('shell.loginHref', null)
                ->where('shell.menu', fn ($menu) => collect($menu)->pluck('label')->all() === ['Bausteine'])
            );
    }

    public function test_the_root_view_applies_the_theme_before_rendering(): void
    {
        // Anti-Flash-Script: setzt die .dark-Klasse synchron im <head>.
        $this->get('/')
            ->assertOk()
            ->assertSee("localStorage.getItem('theme')", false)
            ->assertSee("classList.toggle('dark'", false);
    }

    public function test_flash_messages_are_shared_with_every_page(): void
    {
        $this->withSession(['status' => 'Gespeichert.'])
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.status', 'Gespeichert.'));
    }
}
