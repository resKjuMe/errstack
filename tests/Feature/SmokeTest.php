<?php

namespace Tests\Feature;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_the_start_page_renders(): void
    {
        $this->get('/')->assertOk();
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
