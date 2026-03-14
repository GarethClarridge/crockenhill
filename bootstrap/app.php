<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('calendar:sync')->cron('0 */4 * * *');
        $schedule->command('media:cleanup-temp-files --hours=24')->everySixHours();
        $schedule->command('media:cleanup-unpublished-section-assets --hours=48')
            ->everySixHours()
            ->withoutOverlapping(30);
        $schedule->command('scripture:refresh-passages')
            ->daily()
            ->withoutOverlapping(60);
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // The config repository is not guaranteed to be bound at this bootstrap stage.
        // Read directly from env(); app.trusted_proxies in config/app.php is the canonical reference.
        $trustedProxies = env('TRUSTED_PROXIES');

        if (is_string($trustedProxies) && trim($trustedProxies) !== '') {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'childrens-corner.access' => \App\Http\Middleware\EnsureChildrensCornerAccess::class,
            'mailgun.signature' => \App\Http\Middleware\EnsureValidMailgunWebhookSignature::class,
            'media.process' => \App\Http\Middleware\EnsureMediaProcessingAccess::class,
            'service.access' => \App\Http\Middleware\EnsureServiceTrackingAccess::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/church/members');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->expectsJson() || $request->is('api/*'));

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($e instanceof \App\Contracts\ProvidesSafeMessage && ($request->expectsJson() || $request->is('api/*'))) {
                return response()->json([
                    'message' => $e->getSafeMessage(),
                ], 422);
            }

            return null;
        });
    })->create();
