<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private static bool $viewCacheCleared = false;

    /**
     * Indicates whether the default seeder should run before each test.
     *
     * Disabled for parallel testing performance. Tests that require seeded
     * data should call the relevant seeders explicitly.
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

        // Clear compiled views once per process to prevent stale cached
        // class references after component refactors.
        if (! self::$viewCacheCleared) {
            $this->artisan('view:clear');
            self::$viewCacheCleared = true;
        }
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
