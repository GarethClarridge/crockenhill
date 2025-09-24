<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Indicates whether the default seeder should run before each test.
     * Disabled for better parallel testing performance.
     * Use explicit seeding in individual tests where needed.
     *
     * @var bool
     */
    protected $seed = false;
}
