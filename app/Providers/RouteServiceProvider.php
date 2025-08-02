<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
  /**
   * The path to your application's "home" route.
   *
   * Typically, users are redirected here after authentication.
   *
   * @var string
   */
  public const HOME = '/church/members';

  /**
   * Define your route model bindings, pattern filters, and other route configuration.
   */
  public function boot(): void
  {
    RateLimiter::for('api', function (Request $request) {
      return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

    // Rate limiter for sermon file uploads - more restrictive due to processing overhead
    RateLimiter::for('sermon-upload', function (Request $request) {
      return [
        Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()),
        Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
      ];
    });

    // Rate limiter for sermon processing retries - prevent retry spam
    RateLimiter::for('sermon-retry', function (Request $request) {
      return [
        Limit::perMinute(2)->by($request->user()?->id ?: $request->ip()),
        Limit::perHour(10)->by($request->user()?->id ?: $request->ip()),
      ];
    });

    // Rate limiter for livestream video uploads - very restrictive due to high processing overhead
    RateLimiter::for('livestream-upload', function (Request $request) {
      return [
        Limit::perMinute(1)->by($request->user()?->id ?: $request->ip()),
        Limit::perHour(5)->by($request->user()?->id ?: $request->ip()),
      ];
    });

    // Rate limiter for livestream processing retries - prevent retry spam
    RateLimiter::for('livestream-retry', function (Request $request) {
      return [
        Limit::perMinute(1)->by($request->user()?->id ?: $request->ip()),
        Limit::perHour(3)->by($request->user()?->id ?: $request->ip()),
      ];
    });

    $this->routes(function () {
      Route::middleware('api')
        ->prefix('api')
        ->group(base_path('routes/api.php'));

      Route::middleware('web')
        ->group(base_path('routes/web.php'));
    });
  }
}
