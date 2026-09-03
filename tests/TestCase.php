<?php

declare(strict_types=1);

namespace Tests;

use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Public\MeetingListCache;
use App\Services\Public\PageListCache;
use App\Services\Public\PreacherListCache;
use App\Services\Public\SermonRepository;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use WithCachedConfig;

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
        $this->preventPwnedPasswordsNetworkCall();

        // Fail loudly on any unmocked Laravel Http facade call rather than
        // letting it hang on a real external request. Tests that need an HTTP
        // response set their own Http::fake([...]), which overrides this.
        Http::preventStrayRequests();

        // Clear compiled views once per process to prevent stale cached
        // class references after component refactors.
        if (! self::$viewCacheCleared) {
            $this->artisan('view:clear');
            self::$viewCacheCleared = true;
        }

        $this->app->forgetInstance(SermonRepository::class);
        $this->app->forgetInstance(PageListCache::class);
        $this->app->forgetInstance(PreacherListCache::class);
        $this->app->forgetInstance(MeetingListCache::class);

        // HistoricStagingGuard caches its pristine disk baseline in a static
        // property, deliberately outliving any one instance in production (see
        // its docblock). Many tests configure different disk roots within one
        // PHP process, so without this reset the first test to activate a
        // historic staging context would poison every later one.
        HistoricStagingGuard::resetBaselineForTesting();

        Cache::flush();
    }

    /**
     * Rebind the breach verifier so password validation never reaches
     * api.pwnedpasswords.com during the suite.
     *
     * Production keeps Password::defaults()->uncompromised() byte-for-byte
     * (see AppServiceProvider). Only the test container swaps the verifier for
     * a no-network fake that treats every password as not-breached. A test
     * that genuinely needs to assert breach rejection can rebind locally.
     */
    private function preventPwnedPasswordsNetworkCall(): void
    {
        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function verify($data): bool
            {
                return true;
            }
        });
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
