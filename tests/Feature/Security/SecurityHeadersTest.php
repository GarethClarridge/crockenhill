<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function it_returns_security_headers_on_web_responses(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '0');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->assertHeader('Content-Security-Policy');
    }

    #[Test]
    public function it_returns_security_headers_on_api_responses(): void
    {
        $response = $this->getJson('/api/sermons');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '0');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->assertHeader('Content-Security-Policy');
    }

    #[Test]
    public function it_returns_hsts_header_on_secure_requests(): void
    {
        $response = $this->get('https://localhost/');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[Test]
    public function it_returns_correct_csp_directives(): void
    {
        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    #[Test]
    public function it_includes_upgrade_insecure_requests_in_non_local_environments(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }

    #[Test]
    public function it_does_not_include_upgrade_insecure_requests_in_local_environment(): void
    {
        $this->app['env'] = 'local';

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    #[Test]
    public function it_whitelists_do_spaces_origins_in_csp(): void
    {
        config(['filesystems.disks.do_spaces.endpoint' => 'https://nyc3.digitaloceanspaces.com']);
        config(['filesystems.disks.do_spaces.bucket' => 'my-bucket']);
        config(['filesystems.disks.do_spaces.cdn_endpoint' => 'https://cdn.example.com']);

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://my-bucket.nyc3.digitaloceanspaces.com', $csp);
        $this->assertStringContainsString('https://cdn.example.com', $csp);
    }

    #[Test]
    public function it_adds_local_origins_to_csp_in_local_environment(): void
    {
        $this->app['env'] = 'local';

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('http://localhost:*', $csp);
        $this->assertStringContainsString('ws://localhost:*', $csp);
        $this->assertStringContainsString("font-src 'self' data: http://localhost:*", $csp);
        $this->assertStringContainsString("img-src 'self' data:", $csp);
        $this->assertStringContainsString('http://localhost:*', $csp);
    }

    #[Test]
    public function it_does_not_allow_data_fonts_in_non_local_environment(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("font-src 'self'", $csp);
        $this->assertStringNotContainsString("font-src 'self' data:", $csp);
    }
}
