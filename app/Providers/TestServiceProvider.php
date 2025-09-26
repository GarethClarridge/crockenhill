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
            $this->seedMainDatabaseOnce($token);
        });
    }

    /**
     * Seed the main database once when parallel tests complete.
     * Uses file locking to ensure only one process performs the seeding.
     */
    private function seedMainDatabaseOnce(string $token): void
    {
        $lockFile = storage_path('framework/testing/parallel-seed.lock');
        $lockDir = dirname($lockFile);

        // Ensure the directory exists
        if (! is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        // Small delay to reduce race conditions
        usleep(rand(10000, 100000)); // 10-100ms random delay

        // Check if seeding already completed
        if (file_exists($lockFile . '.completed')) {
            return;
        }

        // Use file locking to ensure only one process seeds the database
        $lockHandle = fopen($lockFile, 'w');

        if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
            try {
                // Double-check completion marker after acquiring lock
                if (file_exists($lockFile . '.completed')) {
                    return;
                }

                // Get the original database name and remove test suffix
                $currentDatabase = config('database.connections.mysql.database');
                $mainDatabase = preg_replace('/_test_\d+$/', '', $currentDatabase);

                // Temporarily switch to main database for seeding
                config(['database.connections.mysql.database' => $mainDatabase]);

                // Clear the connection cache to use new database name
                DB::purge('mysql');

                // Seed the main database
                Artisan::call('db:seed');

                // Mark seeding as completed
                file_put_contents($lockFile . '.completed', "Seeded by process: {$token}");

            } catch (\Exception $e) {
                // Log any seeding errors but don't break test completion
                error_log("Parallel test seeding failed: " . $e->getMessage());
            } finally {
                // Release the lock and clean up
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                @unlink($lockFile);

                // Clean up completion marker after a delay
                register_shutdown_function(function () use ($lockFile) {
                    sleep(1);
                    @unlink($lockFile . '.completed');
                });
            }
        } else {
            // Another process is already seeding, just close our handle
            fclose($lockHandle);
        }
    }
}