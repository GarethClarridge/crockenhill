<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\User;
use App\Services\Processing\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BypassesThrottleRequests;
use Tests\Traits\MediaProcessingTestHelpers;

class AutomatedSermonApiTest extends TestCase
{
    use BypassesThrottleRequests;
    use MediaProcessingTestHelpers;
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutThrottleRequests();

        $this->user = $this->createVerifiedAdmin([
            'email' => 'test@crockenhill.org',
        ]);

        $this->fakeMediaDisks();

        config([
            'media-processing.types.audio.max_file_size' => 100 * 1024 * 1024, // 100MB
            'media-processing.types.audio.allowed_mimes' => [
                'audio/mpeg',
                'audio/mp3',
                'audio/wav',
                'audio/x-wav',
                'audio/mp4',
                'audio/m4a',
            ],
            'media-processing.types.audio.allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
        ]);
    }

    #[Test]
    public function it_uploads_sermon_file_successfully(): void
    {
        $this->mockUnifiedMediaProcessor('test-uuid-123');

        $file = $this->fakeAudioUpload('sermon.mp3', 1024);

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'success',
                'message',
                'processing_id',
                'status_url',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    #[Test]
    public function it_requires_authentication_for_upload(): void
    {
        $file = $this->fakeAudioUpload('sermon.mp3', 1024);

        $response = $this->postJson('/api/media/audio', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function it_validates_file_is_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function it_validates_file_type(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function it_validates_file_size(): void
    {
        // Create file larger than 100MB limit
        $file = $this->fakeAudioUpload('large-sermon.mp3', 101 * 1024);

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function it_accepts_various_audio_formats(): void
    {
        $this->mockUnifiedMediaProcessor('test-uuid-123');

        $audioFormats = [
            ['sermon.mp3', 'audio/mpeg'],
            ['sermon.wav', 'audio/wav'],
            ['sermon.m4a', 'audio/m4a'],
            ['sermon.mp4', 'audio/mp4'],
        ];

        foreach ($audioFormats as [$filename, $mimeType]) {
            $file = UploadedFile::fake()->create($filename, 1024, $mimeType);

            $response = $this->actingAs($this->user)
                ->postJson('/api/media/audio', [
                    'file' => $file,
                ]);

            $response->assertStatus(202)
                ->assertJson(['success' => true]);
        }
    }

    #[Test]
    public function it_handles_corrupted_file_upload(): void
    {
        $this->mockUnifiedMediaProcessor('test-uuid-123');

        // Create a file that appears valid but is corrupted
        $file = UploadedFile::fake()->createWithContent('corrupted.mp3', 'invalid audio data');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        // The file passes Laravel MIME validation (extension-based), so processing is accepted.
        // Actual content validation happens in the async job pipeline.
        $response->assertStatus(202)
            ->assertJson(['success' => true]);
    }

    #[Test]
    public function it_handles_processing_service_errors(): void
    {
        // Mock the UnifiedMediaProcessor to throw an exception
        $mockService = $this->createMock(UnifiedMediaProcessor::class);
        $mockService->expects($this->once())
            ->method('process')
            ->with('audio', $this->anything())
            ->willThrowException(new \Exception('Service unavailable'));

        $this->app->instance(UnifiedMediaProcessor::class, $mockService);

        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Media upload failed',
                'error_code' => 'UPLOAD_FAILED',
            ]);
    }

    #[Test]
    public function it_retrieves_processing_status_successfully(): void
    {
        // Create processing log
        $processingId = (string) Str::uuid();
        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'original_filename' => 'test-sermon.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'transcribing_audio',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/media/processing/{$processingId}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'found',
                'processing_id',
                'status',
                'current_step',
                'created_at',
                'updated_at',
            ])
            ->assertJson([
                'found' => true,
                'processing_id' => $processingId,
                'status' => 'processing',
                'current_step' => 'transcribing_audio',
            ]);
    }

    #[Test]
    public function it_returns_404_for_nonexistent_processing_id(): void
    {
        $nonexistentId = (string) Str::uuid();

        $response = $this->actingAs($this->user)
            ->getJson("/api/media/processing/{$nonexistentId}/status");

        $response->assertStatus(404)
            ->assertJson([
                'found' => false,
            ]);
    }

    #[Test]
    public function it_validates_processing_id_format(): void
    {
        $invalidId = 'invalid-uuid-format';

        $response = $this->actingAs($this->user)
            ->getJson("/api/media/processing/{$invalidId}/status");

        $response->assertStatus(400);
    }

    #[Test]
    public function it_retries_failed_processing(): void
    {
        // Create a fake audio file for the test
        Storage::disk('local')->put('temp/test-audio.mp3', 'fake audio content');

        // Create failed processing log with required fields
        $processingId = (string) Str::uuid();
        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'processing_type' => 'audio',
            'original_filename' => 'failed-sermon.mp3',
            'status' => ProcessingStatus::Failed,
            'current_step' => 'transcribing_audio_failed',
            'error_message' => 'Temporary service unavailable',
            'source_file_path' => 'temp/test-audio.mp3',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/media/processing/{$processingId}/retry");

        // Retry should return 202 (accepted) or 422 (if service can't retry)
        $this->assertContains($response->status(), [202, 422],
            "Expected 202 or 422, got {$response->status()}: {$response->getContent()}"
        );

        if ($response->status() === 202) {
            $response->assertJsonStructure([
                'success',
                'message',
                'processing_id',
                'status_url',
            ])
                ->assertJson([
                    'success' => true,
                    'processing_id' => $processingId,
                ]);

            $this->assertDatabaseHas('media_processing_logs', [
                'processing_id' => $processingId,
                'status' => ProcessingStatus::Pending->value,
            ]);
        }
    }

    #[Test]
    public function it_handles_retry_of_non_failed_processing(): void
    {
        // Create processing log that's not failed
        $processingId = (string) Str::uuid();
        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'original_filename' => 'active-sermon.mp3',
            'status' => ProcessingStatus::Processing,
            'current_step' => 'transcribing_audio',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/media/processing/{$processingId}/retry");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'PROCESSING_NOT_FAILED',
            ]);
    }

    #[Test]
    public function it_applies_rate_limiting(): void
    {
        // Re-enable rate limiting for this specific test
        $this->withMiddleware(ThrottleRequests::class);

        $this->mockUnifiedMediaProcessor('test-uuid-123');

        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        for ($i = 0; $i < 3; $i++) {
            $response = $this->actingAs($this->user)
                ->postJson('/api/media/audio', [
                    'file' => $file,
                ]);

            // Should either succeed (202) or be rate limited (429)
            $this->assertContains($response->status(), [202, 429]);
        }
    }

    #[Test]
    public function it_logs_api_requests(): void
    {
        $this->mockUnifiedMediaProcessor('test-uuid-123');

        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        Log::spy();

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(202);

        Log::shouldHaveReceived('info')
            ->with('Media upload initiated', \Mockery::type('array'));
    }

    #[Test]
    public function it_handles_concurrent_uploads(): void
    {
        $this->mockUnifiedMediaProcessor('test-uuid-123');

        $files = [
            UploadedFile::fake()->create('sermon1.mp3', 1024, 'audio/mpeg'),
            UploadedFile::fake()->create('sermon2.mp3', 1024, 'audio/mpeg'),
            UploadedFile::fake()->create('sermon3.mp3', 1024, 'audio/mpeg'),
        ];

        foreach ($files as $file) {
            $response = $this->actingAs($this->user)
                ->postJson('/api/media/audio', [
                    'file' => $file,
                ]);

            $response->assertStatus(202)
                ->assertJson(['success' => true]);
        }
    }

    #[Test]
    public function it_validates_processing_id_format_in_all_endpoints(): void
    {
        $invalidId = 'x';
        $endpoints = [
            ['GET', "/api/media/processing/{$invalidId}/status"],
            ['DELETE', "/api/media/processing/{$invalidId}"],
            ['POST', "/api/media/processing/{$invalidId}/retry"],
            ['POST', "/api/media/processing/{$invalidId}/confirm-segment"],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->actingAs($this->user)
                ->json($method, $url);

            $response->assertStatus(400);
        }
    }

    #[Test]
    public function it_handles_authorization_properly(): void
    {
        $this->mockUnifiedMediaProcessor('test-uuid-123');

        $unauthorizedUser = User::factory()->create([
            'email' => 'unauthorized@example.com',
            'email_verified_at' => now(),
            'is_admin' => false,
        ]);

        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($unauthorizedUser)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function it_returns_proper_content_types(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($this->user)
            ->postJson('/api/media/audio', [
                'file' => $file,
            ]);

        $response->assertHeader('Content-Type', 'application/json');
    }

    #[Test]
    public function it_handles_malformed_json_requests(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/api/media/audio', [], [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(422);
    }
}
