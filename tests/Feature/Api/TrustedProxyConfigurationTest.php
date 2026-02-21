<?php

namespace Tests\Feature\Api;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    #[Test]
    public function it_does_not_trust_forwarded_ip_headers_without_explicit_proxy_configuration(): void
    {
        $request = Request::create('/__tests/proxy-ip', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        $response = app(TrustProxies::class)->handle($request, static function (Request $request) {
            return response()->json(['ip' => $request->ip()]);
        });

        $this->assertSame('203.0.113.10', $response->getData(true)['ip']);
    }
}
