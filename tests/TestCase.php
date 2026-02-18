<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Indicates whether the default seeder should run before each test.
     *
     * Disabled for parallel testing performance. Database seeding happens
     * automatically after parallel tests complete via TestServiceProvider.
     *
     * @var bool
     */
    protected $seed = false;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Disable throttling middleware during testing to prevent
        // race conditions in parallel test execution
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    /**
     * Clean up per-worker sitemap files written during parallel test runs.
     */
    protected function tearDown(): void
    {
        $token = config('app.test_token');
        if ($token !== null) {
            $path = public_path("sitemap-test-{$token}.xml");
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }
}
