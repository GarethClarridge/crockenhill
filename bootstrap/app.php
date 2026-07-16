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
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // graceTimeInMinutes mirrors each task's withoutOverlapping lock window:
        // the runtime a task is allowed before ScheduledTasksCheck reports it
        // overdue. Tasks without one accept schedule-monitor's 5-minute
        // package default.
        $schedule->command('calendar:sync')
            ->cron('0 */4 * * *')
            ->environments(['production']);
        $schedule->command('sitemap:generate')
            ->dailyAt('04:00')
            ->withoutOverlapping(60)
            ->graceTimeInMinutes(60)
            ->onOneServer()
            ->environments(['production']);
        $schedule->command('media:cleanup-temp-files --hours=24')
            ->everySixHours()
            ->withoutOverlapping(60)
            ->graceTimeInMinutes(60)
            ->environments(['production']);
        $schedule->command('media:cleanup-unpublished-section-assets --hours=48')
            ->everySixHours()
            ->withoutOverlapping(30)
            ->graceTimeInMinutes(30)
            ->environments(['production']);
        $schedule->command('scripture:refresh-passages')
            ->daily()
            ->withoutOverlapping(60)
            ->graceTimeInMinutes(60)
            ->environments(['production']);
        // Writes the cache timestamp ScheduleCheck verifies. Excluded from
        // schedule-monitor (doNotMonitor): ScheduleCheck is its monitor, and
        // per-minute runs would write ~3k task-log rows a day for nothing.
        // Long foreground tasks (backup:run, 120 min) cannot starve this:
        // production's schedule:work starts each minute's schedule:run as a
        // concurrent subprocess, so only same-tick tasks queue behind one
        // another — and the heartbeat is registered ahead of them anyway.
        $schedule->command('health:schedule-check-heartbeat')
            ->everyMinute()
            ->doNotMonitor()
            ->environments(['production']);
        // Runs every registered health check, including the route canaries the
        // retired monitoring:check-canaries command used to probe at this cadence.
        $schedule->command('health:check')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->environments(['production']);
        $schedule->command('model:prune', [
            '--model' => [
                HealthCheckResultHistoryItem::class,
                MonitoredScheduledTaskLogItem::class,
            ],
        ])
            ->daily()
            ->environments(['production']);
        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->environments(['production']);
        $schedule->command('backup:clean')
            ->dailyAt('01:00')
            ->withoutOverlapping(60)
            ->graceTimeInMinutes(60)
            ->onOneServer()
            ->environments(['production']);
        $schedule->command('backup:run')
            ->dailyAt('01:30')
            ->withoutOverlapping(120)
            ->graceTimeInMinutes(120)
            ->onOneServer()
            ->environments(['production']);
        $schedule->command('backup:monitor')
            ->dailyAt('08:00')
            ->withoutOverlapping(30)
            ->graceTimeInMinutes(30)
            ->onOneServer()
            ->environments(['production']);
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        // Run Sanctum's stateful middleware on the API group so same-origin
        // browser requests authenticate via the session cookie, not just a
        // bearer token. The media-processing SSE stream is consumed by an
        // EventSource, which cannot send an Authorization header — without
        // this, the logged-in admin's session cookie is ignored and the
        // stream 401s. Token clients are unaffected (no stateful Origin).
        $middleware->statefulApi();

        // Defence-in-depth: accept browsers that signal `Sec-Fetch-Site:
        // same-origin` or `same-site` as origin-verified, on top of the
        // existing CSRF token check. Token validation continues to apply
        // to any request without those headers.
        $middleware->preventRequestForgery(allowSameSite: true);

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
