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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Security Header: Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Security Header: Prevent Clickjacking (legacy browsers)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Security Header: Prevent Internet Explorer from executing downloads in site's context
        $response->headers->set('X-Download-Options', 'noopen');

        // Security Header: Disable legacy browser XSS filters in favor of CSP
        $response->headers->set('X-XSS-Protection', '0');

        // Security Header: Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Security Header: Cross-Origin Policies
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Security Header: Prevent Flash/PDF from loading cross-domain data
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Security Header: HSTS (Strict-Transport-Security)
        // Only added if the request is secure to avoid breaking local development environments
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Security Header: Permissions Policy
        // Restricts sensitive browser features that this application does not use.
        // ambient-light-sensor is deliberately absent: Chrome never shipped it as a
        // Permissions-Policy feature and logs "Unrecognized feature" on every page.
        $response->headers->set('Permissions-Policy', 'accelerometer=(), autoplay=(self "https://www.youtube.com"), battery=(), bluetooth=(), browsing-topics=(), camera=(), conversion-measurement=(), display-capture=(), document-domain=(), encrypted-media=(), gamepad=(), geolocation=(), gyroscope=(), idle-detection=(), interest-cohort=(), join-ad-interest-group=(), keyboard-map=(), magnetometer=(), microphone=(), payment=(), publickey-credentials-get=(), run-ad-auction=(), screen-wake-lock=(), serial=(), sync-xhr=(), usb=(), web-share=(), window-management=(), xr-spatial-tracking=()');

        // Security Header: Content Security Policy (CSP)
        // Provides an additional layer of security by restricting where resources can be loaded from.
        $this->applyContentSecurityPolicy($response, $request);

        return $response;
    }

    /**
     * Apply Content Security Policy (CSP) headers to the response.
     */
    protected function applyContentSecurityPolicy(Response $response, Request $request): void
    {
        $mediaOrigins = $this->getMediaOrigins();
        $mediaSource = $mediaOrigins !== [] ? ' '.implode(' ', $mediaOrigins) : '';

        // Vite dev server support for local development (HMR, asset serving)
        $isLocal = app()->environment('local');
        $localOrigins = $isLocal ? ' http://localhost:* http://127.0.0.1:* ws://localhost:* ws://127.0.0.1:*' : '';

        $policy = [
            "default-src 'self'",
            // Alpine.js and Livewire 3 require 'unsafe-inline' and 'unsafe-eval' for core functionality.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://pkg.api.bible{$localOrigins}",
            "style-src 'self' 'unsafe-inline'{$localOrigins}",
            "img-src 'self' data: https://www.googletagmanager.com{$mediaSource}{$localOrigins}",
            "connect-src 'self' https://*.google-analytics.com https://api.scripture.api.bible{$mediaSource}{$localOrigins}",
            "font-src 'self'".($isLocal ? ' data: http://localhost:* http://127.0.0.1:*' : ''),
            "media-src 'self'{$mediaSource}",
            "frame-src 'self' https://www.youtube.com",
            // Modern clickjacking protection
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        // Ensure all resources are loaded over HTTPS when the request is secure
        if ($request->isSecure()) {
            $policy[] = 'upgrade-insecure-requests';
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $policy));
    }

    /**
     * Get the allowed origins for media resources (S3/Spaces/CDN).
     *
     * @return array<int, string>
     */
    private function getMediaOrigins(): array
    {
        $origins = [];

        // Whitelist the primary DigitalOcean Spaces endpoint
        $endpoint = config('filesystems.disks.do_spaces.endpoint');
        $bucket = config('filesystems.disks.do_spaces.bucket');

        if (is_string($endpoint) && $endpoint !== '' && is_string($bucket) && $bucket !== '') {
            $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($endpoint, PHP_URL_HOST);

            if ($host) {
                $origins[] = "{$scheme}://{$bucket}.{$host}";
            }
        }

        // Whitelist the CDN endpoint if configured
        $cdnUrl = config('filesystems.disks.do_spaces.cdn_endpoint');
        if (is_string($cdnUrl) && $cdnUrl !== '') {
            $origins[] = $cdnUrl;
        }

        return array_values(array_unique(array_filter($origins)));
    }
}
