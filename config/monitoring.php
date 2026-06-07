<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route Canary Monitoring
    |--------------------------------------------------------------------------
    |
    | The `monitoring:check-canaries` command hits one representative URL per
    | public route type against the live site on a schedule (see
    | bootstrap/app.php) and alerts when one fails. This catches time-delayed
    | regressions that the deploy-time smoke check cannot.
    |
    */

    // Master switch. When false, the scheduled check exits immediately.
    'enabled' => env('MONITORING_CANARIES_ENABLED', true),

    // Base URL the canaries are requested against. Defaults to the app's public
    // URL so the check exercises the real edge (DNS, proxy, TLS). Override with
    // an internal address (e.g. http://localhost) if the host cannot resolve its
    // own public domain.
    'base_url' => env('MONITORING_CANARY_BASE_URL', env('APP_URL', 'http://localhost')),

    // Per-request timeout, in seconds.
    'timeout' => (int) env('MONITORING_CANARY_TIMEOUT', 20),

    // Address that receives failure alerts. When empty, failures are logged only.
    'alert_email' => env('MONITORING_ALERT_EMAIL'),

    // Minimum minutes between alert emails for the same failing URL, so a
    // sustained outage does not send an email on every scheduled run.
    'alert_cooldown_minutes' => (int) env('MONITORING_ALERT_COOLDOWN_MINUTES', 30),

];
