<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_start_page_renders(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk();
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
