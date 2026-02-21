<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigin = $this->getAllowedOrigin($request);
        $origin = $request->header('Origin');

        // Reject unknown origins on preflight requests.
        if ($request->getMethod() === 'OPTIONS') {
            if ($origin !== null && $allowedOrigin === null) {
                $forbidden = response('', Response::HTTP_FORBIDDEN);
                $forbidden->headers->set('Vary', 'Origin');

                return $forbidden;
            }

            return $this->applyCorsHeaders(response('', Response::HTTP_OK), $allowedOrigin, true);
        }

        $response = $next($request);

        return $this->applyCorsHeaders($response, $allowedOrigin, false);
    }

    /**
     * Get the allowed origin for the request.
     */
    private function getAllowedOrigin(Request $request): ?string
    {
        $origin = $request->header('Origin');

        if (! is_string($origin) || $origin === '') {
            return null;
        }

        $allowedOrigins = $this->getConfiguredOrigins();

        return in_array($origin, $allowedOrigins, true) ? $origin : null;
    }

    /**
     * @return list<string>
     */
    private function getConfiguredOrigins(): array
    {
        $appUrl = (string) config('app.url');

        $allowedOrigins = (array) config('app.cors_allowed_origins', [
            $appUrl,
            str_replace('https://', 'https://www.', $appUrl),
        ]);

        // In development, allow localhost origins
        if (app()->environment('local')) {
            $allowedOrigins = array_merge($allowedOrigins, [
                'http://localhost:3000',
                'http://localhost:8000',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:8000',
            ]);
        }

        $filtered = array_values(array_unique(array_filter($allowedOrigins, fn (mixed $value): bool => is_string($value) && $value !== '')));

        return $filtered;
    }

    /**
     * Apply CORS headers for allowed origins only.
     */
    private function applyCorsHeaders(Response $response, ?string $allowedOrigin, bool $isPreflight): Response
    {
        if ($allowedOrigin === null) {
            return $response;
        }

        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Vary', 'Origin');

        if ($isPreflight) {
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
