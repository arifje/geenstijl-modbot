<?php

namespace Tests;

use App\Support\Heartbeat;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Heartbeat::forgetThrottle();
    }
}
