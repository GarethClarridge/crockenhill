<?php

use App\Contracts\ProvidesSafeMessage;
use App\Http\Middleware\EnsureChildrensCornerAccess;
use App\Http\Middleware\EnsureMediaProcessingAccess;
use App\Http\Middleware\EnsureServiceTrackingAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureValidMailgunWebhookSignature;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('calendar:sync')
            ->cron('0 */4 * * *')
            ->environments(['production']);
        $schedule->command('media:cleanup-temp-files --hours=24')
            ->everySixHours()
            ->withoutOverlapping(60)
            ->environments(['production']);
        $schedule->command('media:cleanup-unpublished-section-assets --hours=48')
            ->everySixHours()
            ->withoutOverlapping(30)
            ->environments(['production']);
        $schedule->command('scripture:refresh-passages')
            ->daily()
            ->withoutOverlapping(60)
            ->environments(['production']);
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        // Read directly from env() — TrustProxies must be configured before the
        // container/config cache is fully booted. Intentional, not config drift.
        $trustedProxies = env('TRUSTED_PROXIES');

        if (is_string($trustedProxies) && trim($trustedProxies) !== '') {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'childrens-corner.access' => EnsureChildrensCornerAccess::class,
            'mailgun.signature' => EnsureValidMailgunWebhookSignature::class,
            'media.process' => EnsureMediaProcessingAccess::class,
            'service.access' => EnsureServiceTrackingAccess::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/church/members');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->expectsJson() || $request->is('api/*'));

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof ProvidesSafeMessage && ($request->expectsJson() || $request->is('api/*'))) {
                return response()->json([
                    'message' => $e->getSafeMessage(),
                ], 422);
            }

            return null;
        });
    })->create();
