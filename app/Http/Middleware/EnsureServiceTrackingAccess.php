<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiTokenAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServiceTrackingAccess
{
    /**
     * Enforce privileged access for church service tracking APIs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->is_admin) {
            abort(403, 'Unauthorized action.');
        }

        if (! $user->canAccessAdmin()) {
            abort(403, 'Your email address is not verified.');
        }

        if ($request->bearerToken() !== null && ! $user->tokenCan(ApiTokenAbility::ServiceUpload->value)) {
            abort(403, 'Missing required token ability: '.ApiTokenAbility::ServiceUpload->value);
        }

        return $next($request);
    }
}
