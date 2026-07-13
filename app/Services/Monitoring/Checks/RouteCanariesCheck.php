<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\RouteCanaryProber;
use App\Services\Monitoring\RouteCanaryRegistry;
use Illuminate\Support\Facades\Log;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Folds the route-canary probes into laravel-health so /health is the single
 * monitoring surface: one representative URL per public route type is requested
 * against the live site and failures alert through the standard health
 * notification pipeline (which replaced the bespoke RouteCanaryFailure mail).
 *
 * Dependencies are resolved in run() rather than the constructor because the
 * Check base class serialises its object vars for `health:list` and queued runs.
 */
class RouteCanariesCheck extends Check
{
    public function run(): Result
    {
        if (! config('health.route_canaries.enabled', true)) {
            return Result::make()
                ->ok('Route canary monitoring is disabled.')
                ->shortSummary('Disabled');
        }

        $canaries = app(RouteCanaryRegistry::class)->all();
        $failures = app(RouteCanaryProber::class)->probe($canaries);

        if ($failures === []) {
            return Result::make()
                ->ok()
                ->shortSummary(count($canaries).' passed');
        }

        // Log as well as notify so failures keep surfacing in the log stack
        // with the same detail the old monitoring:check-canaries command gave.
        Log::error('Route canary check failed', ['failures' => $failures]);

        $summary = collect($failures)
            ->map(fn (string $reason, string $url): string => "{$url} — {$reason}")
            ->implode('; ');

        return Result::make()
            ->meta($failures)
            ->failed(count($failures).' of '.count($canaries)." route canaries failed: {$summary}")
            ->shortSummary(count($failures).' failing');
    }
}
