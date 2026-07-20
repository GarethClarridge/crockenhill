<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServiceTrackingEnabled
{
    /**
     * Prevent service-tracking routes from exposing a partial feature surface.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('service-tracking.enabled', true), 404);

        return $next($request);
    }
}
