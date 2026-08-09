<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Einstieg zeigt selbst keine Seite mehr, sondern schickt auf die
     * Übersicht der Organisation (U5) — und ohne Mitgliedschaft in die Liste,
     * wo sich eine anlegen lässt.
     */
    public function test_the_start_page_leads_into_the_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $user->switchOrganization($organization);

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard', $organization));

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('organizations.index'));
    }

    public function test_the_start_page_sends_guests_to_the_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_api_route_file_is_registered(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertJson(['ok' => true]);
    }
}
