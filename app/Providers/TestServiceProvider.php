<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
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
        // Only register parallel testing callbacks in testing environment
        if (! app()->environment('testing')) {
            return;
        }

        // Set up automatic database seeding after parallel tests complete
        ParallelTesting::tearDownProcess(function ($token) {
            $this->seedMainDatabase((string) $token);
        });
    }

    /**
     * Seed the main database once from a single parallel process.
     */
    private function seedMainDatabase(string $token): void
    {
        // Only one worker should seed the main database.
        if ($token !== '1') {
            return;
        }

        try {
            // Get the original database name and remove test suffix
            $currentDatabase = config('database.connections.mysql.database');
            $mainDatabase = preg_replace('/_test_\d+$/', '', $currentDatabase);

            // Temporarily switch to main database for seeding
            config(['database.connections.mysql.database' => $mainDatabase]);

            // Clear the connection cache to use new database name
            DB::purge('mysql');

            // Seed the main database
            Artisan::call('db:seed');
        } catch (\Exception $e) {
            // Log any seeding errors but don't break test completion
            error_log('Parallel test seeding failed: '.$e->getMessage());
        }
    }
}
