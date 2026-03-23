<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__, 3))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(at: fn () => config('app.trusted_hosts', []));
    })
    ->create();
