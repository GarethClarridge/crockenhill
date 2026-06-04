<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Sermon\SermonExposurePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChildrensCornerAccess
{
    public function __construct(
        private readonly SermonExposurePolicy $exposurePolicy,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->exposurePolicy->canAccessChildrensCorner($request->user())) {
            return $next($request);
        }

        return redirect()->guest(route('login'));
    }
}
