<?php

use App\Contracts\ProvidesSafeMessage;
use App\Exceptions\InvalidFileException;
use App\Exceptions\SafeInvalidArgumentException;
use App\Http\Middleware\EnsureChildrensCornerAccess;
use App\Http\Middleware\EnsureMediaProcessingAccess;
use App\Http\Middleware\EnsureServiceTrackingAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureValidMailgunWebhookSignature;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;
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
        // overdue. Tasks without one accept the 5-minute default from
        // config/schedule-monitor.php.
        $schedule->command('calendar:sync')
            ->cron('0 */4 * * *')
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
        // Layer 3 error tracking (Sentry). Purely additive: the render/JSON
        // behaviour below is untouched. Unhandled exceptions flow to Sentry via
        // the documented Laravel 11+/13 hook; handled-and-recovered failures
        // still need an explicit report() at the catch site (see the media
        // pipeline failure handler).
        Integration::handles($exceptions);

        // Don't report the same throwable instance twice. Also the seam that
        // makes the explicit report() in the media pipeline's batch/chain catch
        // safe: the queue integration and that catch see the same instance, so
        // it is reported once, not twice.
        $exceptions->dontReportDuplicates();

        // Burst guard so a runaway loop can't drain the free-tier quota in one
        // incident. Keyed explicitly by error site (class + file + line) rather
        // than Laravel's default of exception class alone: this codebase throws
        // generic \Exception in several places, so a class-only key would let
        // two unrelated incidents share one bucket and suppress each other.
        $exceptions->throttle(fn (Throwable $e) => Limit::perMinute(60)
            ->by($e::class.':'.$e->getFile().':'.$e->getLine()));

        // Expected user-input / domain validation failures surfaced as 422s.
        // They are not incidents, so keep them out of Sentry's noise budget.
        // ProcessingException and its subclasses are deliberately NOT listed —
        // those are real pipeline failures we want reported.
        $exceptions->dontReport([
            InvalidFileException::class,
            SafeInvalidArgumentException::class,
        ]);

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
