<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.cors_allowed_origins', ['https://allowed.example']);
        config()->set('cors.allowed_origins', []);
        config()->set('cors.allowed_origins_patterns', ['#^https://allowed\.example\z#u']);
        config()->set('cors.supports_credentials', true);
    }

    #[Test]
    public function it_allows_preflight_requests_for_allowlisted_origins(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://allowed.example',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/sermons');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://allowed.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    #[Test]
    public function it_rejects_preflight_requests_for_unknown_origins(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/sermons');

        $response->assertNoContent()
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    #[Test]
    public function it_omits_credentials_headers_for_unknown_origins_on_standard_requests(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://evil.example',
        ])->getJson('/api/sermons');

        $response->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }
}
