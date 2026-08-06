<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;

class AutoloadTest extends TestCase
{
    public function test_the_app_namespace_is_autoloaded(): void
    {
        $this->assertTrue(class_exists(AppServiceProvider::class));
    }
}
