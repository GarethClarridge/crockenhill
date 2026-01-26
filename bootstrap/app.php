<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Global middleware (replicated from old Kernel)
        // Note: Some of these might have more modern equivalents in Laravel 12 (e.g., CheckForMaintenanceMode)
        $middleware->use([
            \App\Http\Middleware\TrustProxies::class,
            \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
            \App\Http\Middleware\TrimStrings::class, // Ensure this class exists and is correct
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        // Middleware groups (replicated from old Kernel)
        $middleware->group('web', [
            \App\Http\Middleware\EncryptCookies::class, // Ensure this class exists
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class, // Ensure this class exists
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class, // Was commented out
        ]);

        $middleware->group('api', [
            'throttle:60,1',
            'bindings',      // Uses 'bindings' alias
        ]);

        // Middleware aliases (moved from Kernel)
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class, // Ensure this class exists
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'cors' => \App\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Basic exception handling setup, can be customized further
        // For example:
        // $exceptions->dontReport([
        //     \App\Exceptions\YourCustomException::class,
        // ]);
        // $exceptions->reportable(function (Throwable $e) {
        //     // Custom reporting logic
        // });
        // $exceptions->renderable(function (Throwable $e, $request) {
        //     // Custom rendering logic
        // });
    })->create();
