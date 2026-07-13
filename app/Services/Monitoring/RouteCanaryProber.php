<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\RouteCanary;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Probes route canaries against the live site over HTTP. Extracted from the
 * retired `monitoring:check-canaries` command so the laravel-health
 * RouteCanariesCheck owns scheduling and alerting while the request/assertion
 * logic stays in one place alongside the registry.
 */
class RouteCanaryProber
{
    /**
     * @param  list<RouteCanary>  $canaries
     * @return array<string, string> failures keyed by URL => reason; empty when all pass
     */
    public function probe(array $canaries): array
    {
        $baseUrl = rtrim((string) config('health.route_canaries.base_url'), '/');

        $failures = [];

        foreach ($canaries as $canary) {
            $reason = $this->check($baseUrl, $canary);

            if ($reason !== null) {
                $failures[$canary->url] = $reason;
            }
        }

        return $failures;
    }

    /**
     * Returns null on success, or a human-readable failure reason.
     */
    private function check(string $baseUrl, RouteCanary $canary): ?string
    {
        $timeout = (int) config('health.route_canaries.timeout', 20);

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
}
