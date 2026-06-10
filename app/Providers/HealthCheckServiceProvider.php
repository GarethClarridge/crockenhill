<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Monitoring\Checks\RouteCanariesCheck;
use App\Services\Monitoring\Checks\ScheduledTasksCheck;
use App\Services\Monitoring\Checks\TempDiskSpaceCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\RedisMemoryUsageCheck;
use Spatie\Health\Facades\Health;

/**
 * Registers the application's health checks (June 2026 review, Phase P3).
 *
 * /health is the single monitoring surface: internal resources (database,
 * redis, cache, Horizon, the temp disk that bottlenecks media processing),
 * scheduled-task outcomes recorded by schedule-monitor, and the external
 * route-canary probes folded in from the retired monitoring:check-canaries
 * command.
 *
 * HorizonCheck stands in for the generic QueueCheck deliberately: under the
 * strict-priority queue list a pair of long-running video jobs can starve a
 * heartbeat job on the default queue for hours, so QueueCheck would
 * false-alarm while the workers are healthy.
 */
class HealthCheckServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new(),
            RedisMemoryUsageCheck::new(),
            CacheCheck::new(),
            HorizonCheck::new(),
            TempDiskSpaceCheck::new(),
            ScheduledTasksCheck::new(),
            // Local dev serves through `artisan serve`, where the canaries'
            // self-directed HTTP probes would deadlock the single PHP process.
            RouteCanariesCheck::new()->if(fn (): bool => ! app()->isLocal()),
        ]);
    }
}
