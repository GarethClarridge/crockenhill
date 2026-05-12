<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\MediaProcessingAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMediaProcessingAccess
{
    public function __construct(
        private MediaProcessingAccess $mediaProcessingAccess,
    ) {}

    /**
     * Enforce privileged access for media processing APIs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $denialMessage = $this->mediaProcessingAccess->denialMessage($request);

        if ($denialMessage !== null) {
            abort(403, $denialMessage);
        }

        return $next($request);
    }
}
