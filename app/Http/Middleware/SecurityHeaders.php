<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Security Header: Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Security Header: Prevent Clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Security Header: Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Security Header: HSTS (Strict-Transport-Security)
        // Only added if the request is secure to avoid breaking local development environments
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Security Header: Permissions Policy
        // Restricts sensitive browser features that this application does not use
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Security Header: Content Security Policy (CSP)
        // Provides an additional layer of security by restricting where resources can be loaded from.
        $this->applyContentSecurityPolicy($response);

        return $response;
    }

    /**
     * Apply Content Security Policy (CSP) headers to the response.
     */
    protected function applyContentSecurityPolicy(Response $response): void
    {
        $cdnUrl = config('filesystems.disks.do_spaces.cdn_endpoint');
        $cdnSource = $cdnUrl ? (string) $cdnUrl : '';

        $policy = [
            "default-src 'self'",
            // Alpine.js and Livewire 3 require 'unsafe-inline' and 'unsafe-eval' for core functionality.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://pkg.api.bible",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https://www.googletagmanager.com ".$cdnSource,
            "connect-src 'self' https://*.google-analytics.com https://api.scripture.api.bible ".$cdnSource,
            "font-src 'self'",
            "media-src 'self' ".$cdnSource,
            "frame-src 'self' https://www.youtube.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        // Filter out empty sources (e.g. if CDN is not configured) and join directives
        $directives = array_map('trim', $policy);
        $directives = array_filter($directives, fn (string $directive): bool => ! str_ends_with($directive, ' '));

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));
    }
}
