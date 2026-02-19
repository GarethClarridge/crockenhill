<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMediaProcessingAccess
{
    /**
     * Enforce privileged access for media processing APIs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_admin) {
            abort(403, 'Unauthorized action.');
        }

        if (! $user->hasVerifiedEmail()) {
            abort(403, 'Your email address is not verified.');
        }

        // Session-authenticated first-party clients have no bearer token;
        // ability checks apply when a PAT is used.
        if ($request->bearerToken() !== null && ! $user->tokenCan('media:process')) {
            abort(403, 'Missing required token ability: media:process');
        }

        return $next($request);
    }
}
