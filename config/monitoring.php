<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route Canary Monitoring
    |--------------------------------------------------------------------------
    |
    | The RouteCanariesCheck (registered in HealthCheckServiceProvider, run by
    | the scheduled `health:check` command) hits one representative URL per
    | public route type against the live site and alerts through the
    | laravel-health notification pipeline when one fails. This catches
    | time-delayed regressions that the deploy-time smoke check cannot.
    |
    */

    // Master switch. When false, the check reports ok without probing.
    'enabled' => env('MONITORING_CANARIES_ENABLED', true),

    // Base URL the canaries are requested against. Defaults to the app's public
    // URL so the check exercises the real edge (DNS, proxy, TLS). Override with
    // an internal address (e.g. http://localhost) if the host cannot resolve its
    // own public domain.
    'base_url' => env('MONITORING_CANARY_BASE_URL', env('APP_URL', 'http://localhost')),

    // Per-request timeout, in seconds.
    'timeout' => (int) env('MONITORING_CANARY_TIMEOUT', 20),

];
