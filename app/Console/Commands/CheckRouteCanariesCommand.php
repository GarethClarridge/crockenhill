<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\RouteCanary;
use App\Mail\RouteCanaryFailure;
use App\Services\Monitoring\RouteCanaryRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Continuously verifies each public route type against the live site (scheduled
 * in bootstrap/app.php). Catches time-delayed regressions the deploy-time smoke
 * check cannot — a cache entry repopulating with bad data, a content edit that
 * breaks a page, an expiring certificate.
 *
 * On failure it always logs an error and, when an alert address is configured,
 * emails it (throttled per URL so a sustained outage does not spam). Exits
 * non-zero if any canary failed.
 */
class CheckRouteCanariesCommand extends Command
{
    protected string $signature = 'monitoring:check-canaries';

    protected string $description = 'Hit each public route-type canary against the live site and alert on failure';

    public function handle(RouteCanaryRegistry $registry): int
    {
        if (! config('monitoring.enabled', true)) {
            $this->info('Route canary monitoring is disabled.');

            return self::SUCCESS;
        }

        $baseUrl = rtrim((string) config('monitoring.base_url'), '/');

        /** @var array<string, string> $failures keyed by URL => reason */
        $failures = [];

        foreach ($registry->all() as $canary) {
            $reason = $this->check($baseUrl, $canary);

            if ($reason === null) {
                $this->line("  OK   {$canary->url}");

                continue;
            }

            $failures[$canary->url] = $reason;
            $this->error("  FAIL {$canary->url} — {$reason}");
        }

        if ($failures === []) {
            $this->info('All route canaries passed.');

            return self::SUCCESS;
        }

        $this->report($failures);

        return self::FAILURE;
    }

    /**
     * Returns null on success, or a human-readable failure reason.
     */
    private function check(string $baseUrl, RouteCanary $canary): ?string
    {
        $timeout = (int) config('monitoring.timeout', 20);

        for ($hit = 1; $hit <= $canary->hits; $hit++) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders(['User-Agent' => 'Crockenhill-Canary/1.0'])
                    ->withoutRedirecting()
                    ->get($baseUrl.$canary->url);
            } catch (Throwable $e) {
                return "request failed: {$e->getMessage()}";
            }

            if ($response->status() !== $canary->expectedStatus) {
                return "expected {$canary->expectedStatus}, got {$response->status()}";
            }

            if ($canary->marker !== '' && ! str_contains($response->body(), $canary->marker)) {
                return "body marker \"{$canary->marker}\" missing";
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $failures
     */
    private function report(array $failures): void
    {
        Log::error('Route canary check failed', ['failures' => $failures]);

        $email = config('monitoring.alert_email');

        if (! is_string($email) || $email === '') {
            return;
        }

        $cooldownMinutes = (int) config('monitoring.alert_cooldown_minutes', 30);

        $toAlert = [];

        foreach ($failures as $url => $reason) {
            // Cache::add is atomic and returns false if the key already exists,
            // so we only alert once per URL within the cooldown window.
            if (Cache::add("canary-alert:{$url}", true, now()->addMinutes($cooldownMinutes))) {
                $toAlert[$url] = $reason;
            }
        }

        if ($toAlert === []) {
            return;
        }

        Mail::to($email)->send(new RouteCanaryFailure($toAlert));
    }
}
