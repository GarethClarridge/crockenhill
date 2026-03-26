<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private const TEST_OPENAI_API_KEY = 'testy-test-key';

    private const TEST_OPENAI_BASE_URI = 'http://127.0.0.1:1/v1';

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

        $this->forceSafeOpenAiTestConfiguration();

        // Clear compiled views once per process to prevent stale cached
        // class references after component refactors.
        if (! self::$viewCacheCleared) {
            $this->artisan('view:clear');
            self::$viewCacheCleared = true;
        }

        Cache::flush();
    }

    private function forceSafeOpenAiTestConfiguration(): void
    {
        putenv('OPENAI_API_KEY='.self::TEST_OPENAI_API_KEY);
        putenv('OPENAI_BASE_URL='.self::TEST_OPENAI_BASE_URI);
        $_ENV['OPENAI_API_KEY'] = self::TEST_OPENAI_API_KEY;
        $_ENV['OPENAI_BASE_URL'] = self::TEST_OPENAI_BASE_URI;
        $_SERVER['OPENAI_API_KEY'] = self::TEST_OPENAI_API_KEY;
        $_SERVER['OPENAI_BASE_URL'] = self::TEST_OPENAI_BASE_URI;

        config([
            'openai.api_key' => self::TEST_OPENAI_API_KEY,
            'openai.base_uri' => self::TEST_OPENAI_BASE_URI,
            'media-processing.transcription.openai_api_key' => self::TEST_OPENAI_API_KEY,
            'media-processing.analysis.openai_api_key' => self::TEST_OPENAI_API_KEY,
        ]);
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
