<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__, 3))
    ->withMiddleware(function (Middleware $middleware) {
        $trustedProxies = config('app.trusted_proxies');

        if (is_string($trustedProxies) && trim($trustedProxies) !== '') {
            $middleware->trustProxies(at: $trustedProxies);
        }
    })
    ->create();
