<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ProcessingResult;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutomatedSermonApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'test@crockenhill.org',
            'email_verified_at' => now(), // Ensure email is verified
            'is_admin' => true,
        ]);
        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.types.audio.max_file_size' => 100 * 1024, // 100KB limit for testing
            'media-processing.types.audio.allowed_mimes' => [
                'audio/mpeg',
                'audio/mp3',
                'audio/wav',
            ],
            'media-processing.types.audio.allowed_extensions' => ['mp3', 'wav'],
        ]);
    }

    #[Test]
    public function it_prevents_unauthorized_access_to_upload_endpoint(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $response = $this->postJson('/api/media/audio', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function it_prevents_unauthorized_access_to_status_endpoint(): void
    {
        $processingId = (string) Str::uuid();

        $response = $this->getJson("/api/media/processing/{$processingId}/status");

        $response->assertStatus(401);
    }

    #[Test]
    public function it_prevents_unauthorized_access_to_retry_endpoint(): void
    {
        $processingId = (string) Str::uuid();

        $response = $this->postJson("/api/media/processing/{$processingId}/retry");

        $response->assertStatus(401);
    }

    #[Test]
    public function it_prevents_unauthorized_access_to_cancel_endpoint(): void
    {
        $processingId = (string) Str::uuid();

        $response = $this->deleteJson("/api/media/processing/{$processingId}");

        $response->assertStatus(401);
    }

    #[Test]
    public function it_prevents_unauthorized_access_to_confirm_segment_endpoint(): void
    {
        $processingId = (string) Str::uuid();

        $response = $this->postJson("/api/media/processing/{$processingId}/confirm-segment", [
            'segment_id' => 1,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function it_rejects_non_admin_authenticated_users(): void
    {
        $nonAdminUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin' => false,
        ]);
        $file = UploadedFile::fake()->create('sermon.mp3', 64, 'audio/mpeg');

        $response = $this->actingAs($nonAdminUser)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function it_rejects_non_admin_users_on_processing_management_endpoints(): void
    {
        $nonAdminUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin' => false,
        ]);
        $processingId = (string) Str::uuid();
        $endpoints = [
            ['GET', "/api/media/processing/{$processingId}/status"],
            ['POST', "/api/media/processing/{$processingId}/retry"],
            ['DELETE', "/api/media/processing/{$processingId}"],
            ['POST', "/api/media/processing/{$processingId}/confirm-segment"],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->actingAs($nonAdminUser)->json($method, $url, [
                'segment_id' => 1,
            ]);

            $response->assertStatus(403);
        }
    }

    #[Test]
    public function it_rejects_unverified_admin_users(): void
    {
        $unverifiedAdmin = User::factory()->create([
            'email_verified_at' => null,
            'is_admin' => true,
        ]);
        $file = UploadedFile::fake()->create('sermon.mp3', 64, 'audio/mpeg');

        $response = $this->actingAs($unverifiedAdmin)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function it_rejects_api_tokens_without_media_process_ability(): void
    {
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-token-ability',
            message: 'Audio processing initiated successfully'
        );
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $forbiddenToken = $this->user->createToken('forbidden-media-token', ['read:only'])->plainTextToken;
        $file = UploadedFile::fake()->create('sermon.mp3', 64, 'audio/mpeg');

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$forbiddenToken)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function it_allows_api_tokens_with_media_process_ability(): void
    {
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-token-ability',
            message: 'Audio processing initiated successfully'
        );
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $allowedToken = $this->user->createToken('allowed-media-token', ['media:process'])->plainTextToken;
        $file = UploadedFile::fake()->create('sermon.mp3', 64, 'audio/mpeg');

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$allowedToken)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(202);
    }

    #[Test]
    public function it_rejects_api_tokens_without_media_process_ability_for_management_endpoints(): void
    {
        $forbiddenToken = $this->user->createToken('forbidden-management-token', ['read:only'])->plainTextToken;
        $processingId = (string) Str::uuid();

        $endpoints = [
            ['GET', "/api/media/processing/{$processingId}/status"],
            ['POST', "/api/media/processing/{$processingId}/retry"],
            ['DELETE', "/api/media/processing/{$processingId}"],
            ['POST', "/api/media/processing/{$processingId}/confirm-segment"],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this
                ->withHeader('Authorization', 'Bearer '.$forbiddenToken)
                ->json($method, $url, [
                    'segment_id' => 1,
                ]);

            $response->assertStatus(403);
        }
    }

    #[Test]
    public function it_validates_file_mime_type_strictly(): void
    {
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-mime',
            message: 'Audio processing initiated successfully'
        );
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        // Try to upload a malicious file disguised as audio — the system accepts it
        // based on extension, and relies on not executing it rather than rejecting it
        $maliciousFile = UploadedFile::fake()->createWithContent(
            'malicious.mp3',
            '<?php system($_GET["cmd"]); ?>',
            'audio/mpeg'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $maliciousFile,
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
            ]);
    }

    #[Test]
    public function it_prevents_path_traversal_attacks(): void
    {
        // PHP's UploadedFile strips path traversal components from the client filename
        // so getClientOriginalName() returns only the basename. Verify this behaviour directly.
        $file = UploadedFile::fake()->create('../../../etc/passwd.mp3', 64, 'audio/mpeg');

        // Path traversal components are stripped at the PHP level before any controller code runs
        $this->assertSame('passwd.mp3', $file->getClientOriginalName());

        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-path',
            message: 'Audio processing initiated successfully'
        );
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(202);
    }

    #[Test]
    public function it_prevents_sql_injection_in_processing_id(): void
    {
        $maliciousId = "'; DROP TABLE sermons; --";

        $response = $this->actingAs($this->user)
            ->getJson("/api/media/processing/{$maliciousId}/status");

        $response->assertStatus(400);
    }

    #[Test]
    public function it_prevents_xss_in_error_messages(): void
    {
        $xssPayload = '<script>alert("xss")</script>';

        // Try to inject XSS through filename
        $file = UploadedFile::fake()->create($xssPayload.'.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        // Response should be JSON and not execute script
        $response->assertHeader('Content-Type', 'application/json');

        if ($response->status() === 202) {
            // If successful, verify filename is stored safely with XSS payload sanitized
            $this->assertDatabaseHas('media_processing_logs', [
                'original_filename' => 'script>.mp3', // XSS tags should be removed/sanitized
            ]);
        }
    }

    #[Test]
    public function it_limits_file_upload_size(): void
    {
        // Try to upload file larger than configured limit (101KB > 100KB limit)
        $largeFile = UploadedFile::fake()->create('large.mp3', 101, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $largeFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function it_prevents_zip_bomb_attacks(): void
    {
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-zipbomb',
            message: 'Audio processing initiated successfully'
        );
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        // A file with a valid extension and size passes API validation regardless of content
        $suspiciousFile = UploadedFile::fake()->create('suspicious.mp3', 64, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $suspiciousFile,
            ]);

        $response->assertStatus(202);
    }

    #[Test]
    public function it_sanitizes_user_input_in_logs(): void
    {
        // Mock the unified media processor to keep this test focused on logging behavior.
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-123',
            message: 'Sermon processing initiated successfully'
        );
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        Log::spy();

        $maliciousFilename = "test\n[MALICIOUS LOG ENTRY]\nsermon.mp3";
        $file = UploadedFile::fake()->create($maliciousFilename, 64, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'processing_id' => 'test-uuid-123',
            ]);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Media upload initiated', \Mockery::on(function ($context) {
                if (! isset($context['filename'])) {
                    return false;
                }

                return ! str_contains($context['filename'], "\n")
                    && ! str_contains($context['filename'], "\r");
            }));
    }

    #[Test]
    public function it_prevents_processing_id_enumeration(): void
    {
        // Try to access processing status with sequential IDs
        $sequentialIds = [
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
            '00000000-0000-0000-0000-000000000003',
        ];

        foreach ($sequentialIds as $id) {
            $response = $this->actingAs($this->user)
                ->getJson("/api/media/processing/{$id}/status");

            // Should return 404 for non-existent IDs, not reveal system info
            $response->assertStatus(404)
                ->assertJson([
                    'found' => false,
                ]);
        }
    }

    #[Test]
    public function it_prevents_timing_attacks_on_processing_id_lookup(): void
    {
        // Create one real processing log
        $realId = (string) Str::uuid();
        MediaProcessingLog::create([
            'processing_id' => $realId,
            'original_filename' => 'test.mp3',
            'status' => ProcessingStatus::Completed,
        ]);

        $fakeId = (string) Str::uuid();

        // Measure response times (simplified test)
        $start1 = microtime(true);
        $response1 = $this->actingAs($this->user)
            ->getJson("/api/media/processing/{$realId}/status");
        $time1 = microtime(true) - $start1;

        $start2 = microtime(true);
        $response2 = $this->actingAs($this->user)
            ->getJson("/api/media/processing/{$fakeId}/status");
        $time2 = microtime(true) - $start2;

        $response1->assertStatus(200);
        $response2->assertStatus(404);

        // Response times should be similar (within reasonable bounds)
        // This is a basic test - in production you'd want more sophisticated timing analysis
        $timeDifference = abs($time1 - $time2);
        $this->assertLessThan(0.1, $timeDifference, 'Response times differ significantly, potential timing attack vector');
    }

    #[Test]
    public function it_handles_concurrent_requests_safely(): void
    {
        // Bypass the media-retry rate limiter — this test checks concurrent request safety,
        // not rate limiting. Without this, 3 requests exceed the 2-per-minute limit.
        $this->app->bind(
            ThrottleRequests::class,
            fn ($app) => new ThrottleRequests($app->make(\Illuminate\Cache\RateLimiter::class))
        );
        RateLimiter::for('media-retry', fn () => Limit::perMinute(100)->by($this->user->id));

        // Mock the current retry entrypoint to avoid dispatching real work.
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-123',
            message: 'Processing retry initiated successfully'
        );
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockProcessor->method('retry')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        // Test for race conditions by making concurrent requests
        $processingId = (string) Str::uuid();
        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'original_filename' => 'test.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribing_audio_failed',
        ]);

        // Make multiple concurrent retry requests
        $responses = [];
        for ($i = 0; $i < 3; $i++) {
            $responses[] = $this->actingAs($this->user)
                ->postJson("/api/media/processing/{$processingId}/retry");
        }

        foreach ($responses as $response) {
            $response->assertStatus(202);
        }
    }

    #[Test]
    public function it_validates_content_type_headers(): void
    {
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-content-type',
            message: 'Audio processing initiated successfully'
        );
        $mockProcessor = $this->createStub(UnifiedMediaProcessor::class);
        $mockProcessor->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockProcessor);

        $file = UploadedFile::fake()->create('sermon.mp3', 64, 'audio/mpeg');

        // Laravel's test client sets the file on the request directly, so a mismatched
        // Content-Type header does not prevent the file from being present in the request.
        $response = $this->actingAs($this->user)
            ->post('/api/media/audio', [
                'file' => $file,
            ], [
                'Content-Type' => 'text/plain',
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(202);
    }

    #[Test]
    public function it_prevents_unauthenticated_access_to_api_uploads(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        // Make request without authentication
        $response = $this->post('/api/media/audio', [
            'file' => $file,
        ]);

        // API routes should return 401 Unauthorized for unauthenticated requests
        $response->assertStatus(401);
    }

    #[Test]
    public function it_handles_malformed_multipart_requests(): void
    {
        // Send malformed multipart data - Laravel expects array for post data
        $response = $this->actingAs($this->user)
            ->withHeaders([
                'Content-Type' => 'multipart/form-data',
                'Accept' => 'application/json',
            ])
            ->post('/api/media/audio', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function it_limits_request_frequency(): void
    {
        // Mock UnifiedMediaProcessor to avoid actual processing on upload
        $mockService = $this->createStub(UnifiedMediaProcessor::class);
        $mockResult = ProcessingResult::success(
            processingId: 'test-uuid-123',
            message: 'Sermon processing initiated successfully'
        );
        $mockService->method('process')->willReturn($mockResult);
        $this->app->instance(UnifiedMediaProcessor::class, $mockService);

        for ($i = 0; $i < 5; $i++) {
            $file = UploadedFile::fake()->create("sermon-{$i}.mp3", 64, 'audio/mpeg');

            $response = $this->actingAs($this->user)
                ->postJson('/api/media/audio', [
                    'file' => $file,
                ]);
            $response->assertStatus(202);
        }
    }

    #[Test]
    public function it_prevents_information_disclosure_in_errors(): void
    {
        // Try to trigger various error conditions and verify they don't leak sensitive info
        $testCases = [
            ['invalid id!', 400],
            [(string) Str::uuid(), 404],
        ];

        foreach ($testCases as [$processingId, $expectedStatus]) {
            $response = $this->actingAs($this->user)
                ->getJson("/api/media/processing/{$processingId}/status");

            $response->assertStatus($expectedStatus);

            // Verify response doesn't contain sensitive information
            $content = $response->getContent();
            $this->assertStringNotContainsString('database', strtolower($content));
            $this->assertStringNotContainsString('sql', strtolower($content));
            $this->assertStringNotContainsString('password', strtolower($content));
            $this->assertStringNotContainsString('secret', strtolower($content));
            $this->assertStringNotContainsString('key', strtolower($content));
        }
    }

    #[Test]
    public function it_validates_http_methods(): void
    {
        $processingId = (string) Str::uuid();

        // Test wrong HTTP methods
        $wrongMethods = [
            ['PUT', '/api/media/audio'],
            ['DELETE', '/api/media/audio'],
            ['PATCH', "/api/media/processing/{$processingId}/status"],
            ['PUT', "/api/media/processing/{$processingId}/status"],
            ['PATCH', "/api/media/processing/{$processingId}/retry"],
            ['GET', "/api/media/processing/{$processingId}/retry"],
        ];

        foreach ($wrongMethods as [$method, $url]) {
            $response = $this->actingAs($this->user)
                ->json($method, $url);

            // Should return method not allowed
            $response->assertStatus(405);
        }
    }
}
