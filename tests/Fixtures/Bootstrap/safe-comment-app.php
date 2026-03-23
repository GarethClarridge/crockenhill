<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__, 3))
    ->withMiddleware(function (Middleware $middleware) {
        // config('app.trusted_hosts') is safe when deferred to runtime.
        $middleware->redirectGuestsTo('/login');
    })
    ->create();
