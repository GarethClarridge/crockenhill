<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PoofClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PoofClientTest extends TestCase
{
    private PoofClient $client;

    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.poof.enabled', true);
        Config::set('services.poof.api_key', 'pk_test_123');
        Config::set('services.poof.base_url', 'https://api.poof.bg/v1');
        Config::set('services.poof.timeout_seconds', 15);
        Config::set('services.poof.max_retries', 2);

        $this->client = new PoofClient;
        $this->imagePath = tempnam(sys_get_temp_dir(), 'poof-client-');
        file_put_contents($this->imagePath, 'fake-image-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);

        parent::tearDown();
    }

    #[Test]
    public function it_sends_the_expected_request_to_poof(): void
    {
        Http::fake([
            'https://api.poof.bg/v1/remove' => Http::response('foreground-bytes', 200),
        ]);

        $result = $this->client->removeBackground($this->imagePath);

        $this->assertSame('foreground-bytes', $result['contents']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.poof.bg/v1/remove'
                && $request->isMultipart()
                && $request->hasHeader('x-api-key', 'pk_test_123')
                && str_contains($request->body(), 'name="format"')
                && str_contains($request->body(), 'webp')
                && str_contains($request->body(), 'name="size"')
                && str_contains($request->body(), 'full')
                && str_contains($request->body(), 'name="crop"')
                && str_contains($request->body(), 'false');
        });
    }

    #[Test]
    public function it_retries_retryable_provider_errors(): void
    {
        Http::fake([
            'https://api.poof.bg/v1/remove' => Http::sequence()
                ->push(['code' => 'rate_limit_exceeded', 'request_id' => 'req_1'], 429)
                ->push(['code' => 'upstream_error', 'request_id' => 'req_2'], 502)
                ->push('foreground-bytes', 200),
        ]);

        $result = $this->client->removeBackground($this->imagePath);

        $this->assertSame('foreground-bytes', $result['contents']);
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_returns_null_for_terminal_payment_required_errors(): void
    {
        Http::fake([
            'https://api.poof.bg/v1/remove' => Http::response([
                'code' => 'payment_required',
                'message' => 'Insufficient credits',
                'request_id' => 'req_credits',
            ], 402),
        ]);

        $result = $this->client->removeBackground($this->imagePath);

        $this->assertNull($result);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_returns_null_when_disabled(): void
    {
        Config::set('services.poof.enabled', false);

        $this->assertNull((new PoofClient)->removeBackground($this->imagePath));
    }
}
