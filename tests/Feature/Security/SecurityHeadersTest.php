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
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }

    #[Test]
    public function it_returns_security_headers_on_api_responses(): void
    {
        $response = $this->getJson('/api/sermons');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }
}
