<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Jede Seite rendert über das Root-Blade mit @vite. Ohne diesen Aufruf
        // bräuchte die Testsuite ein zuvor gebautes Manifest (public/build) —
        // die Tests sollen aber auch vor dem ersten `npm run build` laufen.
        $this->withoutVite();
    }
}
