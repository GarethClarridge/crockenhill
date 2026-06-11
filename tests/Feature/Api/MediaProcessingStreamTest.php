<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class MediaProcessingStreamTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createVerifiedAdmin([
            'email' => 'sse-admin-'.Str::random(8).'@test.com',
            'password' => bcrypt('password'),
        ]);

        // Keep the SSE loop tight in tests so feature tests don't stall on sleep().
        config()->set('media-processing.sse.poll_seconds', 0);
        config()->set('media-processing.sse.max_duration_seconds', 5);
    }

    #[Test]
    public function stream_endpoint_returns_event_stream_content_type(): void
    {
        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->completed()
            ->state(['processing_id' => $processingId])
            ->create();

        $response = $this->actingAs($this->admin)
            ->get("/api/media/processing/{$processingId}/stream");

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    #[Test]
    public function stream_emits_progress_event_with_snapshot_payload(): void
    {
        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->completed()
            ->state([
                'processing_id' => $processingId,
                'original_filename' => 'test.mp3',
            ])
            ->create();

        $body = $this->captureStream("/api/media/processing/{$processingId}/stream");

        $this->assertStringContainsString('event: progress', $body);
        $this->assertStringContainsString('"processing_id":"'.$processingId.'"', $body);
        $this->assertStringContainsString('"status":"completed"', $body);
    }

    #[Test]
    public function stream_closes_on_terminal_status_with_end_signal(): void
    {
        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->failed('boom')
            ->state(['processing_id' => $processingId])
            ->create();

        $body = $this->captureStream("/api/media/processing/{$processingId}/stream");

        // Snapshot event first, then the </stream> sentinel emitted by endStreamWith.
        $this->assertStringContainsString('"status":"failed"', $body);
        $this->assertStringContainsString('data: </stream>', $body);
    }

    #[Test]
    public function stream_endpoint_requires_authentication(): void
    {
        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->completed()
            ->state(['processing_id' => $processingId])
            ->create();

        $response = $this->get("/api/media/processing/{$processingId}/stream");

        $response->assertStatus(401);
    }

    #[Test]
    public function api_group_runs_sanctum_stateful_middleware_for_cookie_auth(): void
    {
        // The SSE stream is consumed by an EventSource, which can only send
        // cookies — never a bearer token. Sanctum's web-guard fallback can
        // only read that session cookie if EnsureFrontendRequestsAreStateful
        // (and the session middleware it pulls in) runs on the API group.
        // Without it, the logged-in admin's request 401s in the browser even
        // though actingAs() tests pass, so guard the middleware wiring here.
        $apiMiddleware = Route::getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            $apiMiddleware,
            'API group must run Sanctum stateful middleware so EventSource/cookie clients authenticate.'
        );
    }

    #[Test]
    public function stream_endpoint_is_rate_limited(): void
    {
        RateLimiter::clear('processing-stream:'.$this->admin->id);

        $processingId = Str::uuid()->toString();

        $this->processingLogScenario()
            ->completed()
            ->state(['processing_id' => $processingId])
            ->create();

        // 10 connections per minute per user — the 11th should 429.
        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($this->admin)
                ->get("/api/media/processing/{$processingId}/stream");
            $response->assertOk();
        }

        $this->actingAs($this->admin)
            ->get("/api/media/processing/{$processingId}/stream")
            ->assertStatus(429);
    }

    private function captureStream(string $url): string
    {
        $response = $this->actingAs($this->admin)->get($url);
        $response->assertOk();

        $baseResponse = $response->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $baseResponse);

        // The framework's eventStream generator calls ob_flush() then flush() between yields.
        // ob_flush() pushes to the next-lower buffer level, so we nest two buffers — the
        // generator drains its inner buffer into our outer buffer, which we then read.
        ob_start();
        ob_start();
        try {
            $baseResponse->sendContent();
            ob_end_flush();
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }
}
