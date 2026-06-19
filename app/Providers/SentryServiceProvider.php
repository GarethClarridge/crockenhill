<?php

declare(strict_types=1);

namespace App\Providers;

use App\Sentry\ScrubSensitiveRequestData;
use Illuminate\Support\ServiceProvider;
use Sentry\SentrySdk;

/**
 * Wires the request-URL scrubber into the Sentry client at runtime.
 *
 * The scrubbing logic is a Sentry `before_send` callback, but it cannot live in
 * config/sentry.php: the production deploy runs `php artisan optimize`
 * (config:cache), which refuses to serialize a closure. Setting it on the
 * client's Options here keeps the config cacheable while still redacting
 * sensitive query parameters at the final stage before transmission.
 *
 * Guarded by a configured DSN so local, CI, and test environments — where the
 * DSN is intentionally empty — stay a pure no-op and never build the client.
 */
class SentryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (blank(config('sentry.dsn'))) {
            return;
        }

        $client = SentrySdk::getCurrentHub()->getClient();

        if ($client === null) {
            return;
        }

        $client->getOptions()->setBeforeSendCallback(new ScrubSensitiveRequestData);
    }
}
