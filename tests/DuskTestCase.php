<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Flush the cache used by the Dusk-served Laravel app.
     *
     * The test process uses CACHE_DRIVER=array (per .env.testing), but the
     * Dusk-served app uses a persistent store. Calling Cache::forget() in
     * tests only clears the per-process array store, so cached read models
     * from one test can hide data created by the next. Flushing the served
     * app's store here keeps each test isolated from prior runs.
     *
     * Local dev uses redis and CI uses file. Flush only the configured Dusk
     * store so each environment clears the cache used by its served app.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $cacheStore = config('cache.default');

        if (in_array($cacheStore, ['file', 'redis'], true)) {
            Cache::store($cacheStore)->flush();
        }
    }

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail() && ! (isset($_ENV['DUSK_DRIVER_URL']) || getenv('DUSK_DRIVER_URL'))) {
            static::startChromeDriver(['--port=9515']);
        }

        // Move the Vite hot file aside so Chrome uses the built manifest
        // instead of the dev server (which is unreachable from the selenium container).
        $hot = dirname(__DIR__).'/public/hot';
        if (file_exists($hot)) {
            rename($hot, $hot.'.bak');
        }
    }

    /**
     * After all tests have run, restore the Vite hot file.
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        $hot = dirname(__DIR__).'/public/hot';
        if (file_exists($hot.'.bak')) {
            rename($hot.'.bak', $hot);
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? getenv('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
