<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class TestServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Keep test bootstrap side-effect free. Test suites that need seeded
        // data should seed explicitly instead of mutating the shared test
        // database after parallel runs complete.
    }
}
