<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PixianClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PixianClientTest extends TestCase
{
    private PixianClient $client;

    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.pixian.enabled', true);
        Config::set('services.pixian.api_id', 'pixian_test_id');
        Config::set('services.pixian.api_secret', 'pixian_test_secret');
        Config::set('services.pixian.base_url', 'https://api.pixian.ai/api/v2');
        Config::set('services.pixian.timeout_seconds', 180);
        Config::set('services.pixian.test', true);

        $this->client = new PixianClient;
        $this->imagePath = tempnam(sys_get_temp_dir(), 'pixian-client-');
        file_put_contents($this->imagePath, 'fake-image-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);

        parent::tearDown();
    }

    #[Test]
    public function it_sends_the_expected_request_to_pixian(): void
    {
        Http::fake([
            'https://api.pixian.ai/api/v2/remove-background' => Http::response('foreground-bytes', 200, [
                'x-request-id' => 'req_pixian_123',
            ]),
        ]);

        $result = $this->client->removeBackground($this->imagePath);

        $this->assertSame('foreground-bytes', $result['contents']);
        $this->assertSame('req_pixian_123', $result['request_id']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.pixian.ai/api/v2/remove-background'
                && $request->isMultipart()
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('pixian_test_id:pixian_test_secret'))
                && str_contains($request->body(), 'name="image"')
                && str_contains($request->body(), 'name="output.format"')
                && str_contains($request->body(), 'png')
                && str_contains($request->body(), 'name="result.crop_to_foreground"')
                && str_contains($request->body(), 'false')
                && str_contains($request->body(), 'name="test"')
                && str_contains($request->body(), 'true');
        });
    }

    #[Test]
    public function it_returns_null_for_terminal_errors(): void
    {
        Http::fake([
            'https://api.pixian.ai/api/v2/remove-background' => Http::response([
                'error' => 'Insufficient credits',
            ], 402),
        ]);

        $result = $this->client->removeBackground($this->imagePath);

        $this->assertNull($result);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_returns_null_when_disabled(): void
    {
        Config::set('services.pixian.enabled', false);

        $this->assertNull((new PixianClient)->removeBackground($this->imagePath));
    }
}
