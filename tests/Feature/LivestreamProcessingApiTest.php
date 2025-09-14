<?php

namespace Tests\Feature;

use App\Models\LivestreamProcessingLog;
use App\Models\LivestreamSegment;
use App\Models\Sermon;
use App\Models\User;
use App\Services\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LivestreamProcessingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        // Mock the VideoSegmentationService to avoid FFmpeg validation issues in tests
        $this->mock(VideoSegmentationService::class, function ($mock) {
            $mock->shouldReceive('validateVideoFile')->andReturn(true);
            $mock->shouldReceive('getVideoMetadata')->andReturn([
                'duration' => 3600.0,
                'format_name' => 'mp4',
                'size' => 50000000,
                'bit_rate' => 1000000,
                'width' => 1920,
                'height' => 1080,
                'codec' => 'h264',
            ]);
        });

        Bus::fake();

        Config::set('livestream-processing', [
            'max_file_size' => 2147483648, // 2GB
            'supported_formats' => ['mp4', 'mov', 'avi', 'mkv'],
        ]);
    }

    public function test_upload_livestream_video_successfully()
    {
        $user = User::factory()->create();
        $videoFile = UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4');

        $response = $this->actingAs($user)
            ->postJson('/api/livestreams/process', [
                'video' => $videoFile,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'processing_id',
                'status_url',
                'estimated_completion',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Livestream processing initiated',
            ]);

        $processingId = $response->json('processing_id');

        // Verify database record was created
        $this->assertDatabaseHas('livestream_processing_logs', [
            'processing_id' => $processingId,
            'original_filename' => 'livestream.mp4',
            'status' => 'pending',
        ]);

        // Verify job chain was dispatched with new livestream processing jobs
        Bus::assertChained([
            \App\Jobs\GenerateRmsLog::class,
            \App\Jobs\AnalyzeSegments::class,
            \App\Jobs\ExtractSermon::class,
            \App\Jobs\SubmitToProcessing::class,
            \App\Jobs\TranscribeSermonAudioFromLivestream::class,
            \App\Jobs\AnalyzeSermonTranscriptFromLivestream::class,
            \App\Jobs\GenerateThumbnail::class,
            \App\Jobs\CleanupTemporaryFiles::class,
        ]);
    }

    public function test_upload_livestream_video_requires_authentication()
    {
        $videoFile = UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4');

        $response = $this->postJson('/api/livestreams/process', [
            'video' => $videoFile,
        ]);

        $response->assertStatus(401);
    }

    public function test_upload_livestream_video_validation_missing_file()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/livestreams/process', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['video']);
    }

    public function test_upload_livestream_video_validation_invalid_format()
    {
        $user = User::factory()->create();
        $invalidFile = UploadedFile::fake()->create('document.txt', 1000, 'text/plain');

        $response = $this->actingAs($user)
            ->postJson('/api/livestreams/process', [
                'video' => $invalidFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['video']);
    }

    public function test_upload_livestream_video_validation_file_too_large()
    {
        Config::set('livestream-processing.max_file_size', 1000); // 1KB limit

        $user = User::factory()->create();
        $largeFile = UploadedFile::fake()->create('large.mp4', 2000, 'video/mp4'); // 2KB file

        $response = $this->actingAs($user)
            ->postJson('/api/livestreams/process', [
                'video' => $largeFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['video']);
    }

    public function test_upload_livestream_video_with_options()
    {
        $user = User::factory()->create();
        $videoFile = UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4');

        $response = $this->actingAs($user)
            ->postJson('/api/livestreams/process', [
                'video' => $videoFile,
                'options' => [
                    'rms_threshold' => -25.0,
                    'min_sermon_duration' => 600,
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'processing_id',
                'status_url',
                'estimated_completion',
            ]);
    }

    public function test_get_processing_status_successfully()
    {
        $user = User::factory()->create();

        $processing = LivestreamProcessingLog::factory()->create([
            'processing_id' => '12345678-1234-1234-1234-123456789abc',
            'status' => 'segmenting',
            'original_filename' => 'test-video.mp4',
            'sermon_processing_id' => 'sermon-123',
        ]);

        // Debug: Check if processing record was created
        $this->assertDatabaseHas('livestream_processing_logs', [
            'processing_id' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $segments = LivestreamSegment::factory()->count(3)->create([
            'processing_id' => '12345678-1234-1234-1234-123456789abc',
            'processing_log_id' => $processing->id,
        ]);

        LivestreamSegment::factory()->create([
            'processing_id' => '12345678-1234-1234-1234-123456789abc',
            'processing_log_id' => $processing->id,
            'classification' => 'speech',
            'is_sermon_candidate' => true,
            'start_time' => 300,
            'end_time' => 1800,
        ]);

        // Debug: Check if segments were created
        $this->assertDatabaseCount('livestream_segments', 4);

        $response = $this->actingAs($user)
            ->getJson('/api/livestreams/processing/12345678-1234-1234-1234-123456789abc/status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'processing_id',
                'status',
                'current_step',
                'progress_percentage',
                'segments_identified',
                'sermon_processing_id',
                'segments' => [
                    '*' => [
                        'index',
                        'start_time',
                        'end_time',
                        'classification',
                    ],
                ],
            ])
            ->assertJson([
                'processing_id' => '12345678-1234-1234-1234-123456789abc',
                'status' => 'segmenting',
                'segments_identified' => 4,
                'sermon_processing_id' => 'sermon-123',
            ]);

        // Check that sermon segment is marked
        $segments = $response->json('segments');
        $sermonSegment = collect($segments)->firstWhere('is_sermon', true);
        $this->assertNotNull($sermonSegment);
        $this->assertEquals(300, $sermonSegment['start_time']);
        $this->assertEquals(1800, $sermonSegment['end_time']);
        $this->assertEquals('speech', $sermonSegment['classification']);
    }

    public function test_get_processing_status_not_found()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/livestreams/processing/nonexistent-id/status');

        $response->assertStatus(404);
    }

    public function test_get_processing_status_requires_authentication()
    {
        $processing = LivestreamProcessingLog::factory()->create([
            'processing_id' => '12345678-1234-1234-1234-123456789abc',
        ]);

        $response = $this->getJson('/api/livestreams/processing/12345678-1234-1234-1234-123456789abc/status');

        $response->assertStatus(401);
    }

    public function test_processing_status_shows_progress_percentage()
    {
        $user = User::factory()->create();

        // Test different statuses and their expected progress
        $testCases = [
            'pending' => 0,
            'processing' => 25,
            'segmenting' => 50,
            'extraction_complete' => 75,
            'completed' => 100,
            'failed' => 0,
        ];

        foreach ($testCases as $status => $expectedProgress) {
            $processingId = \Illuminate\Support\Str::uuid();
            $processing = LivestreamProcessingLog::factory()->create([
                'processing_id' => $processingId,
                'status' => $status,
            ]);

            $response = $this->actingAs($user)
                ->getJson("/api/livestreams/processing/{$processingId}/status");

            $response->assertStatus(200)
                ->assertJson([
                    'processing_id' => $processingId,
                    'status' => $status,
                    'progress_percentage' => $expectedProgress,
                ]);
        }
    }

    public function test_processing_status_with_video_path()
    {
        $user = User::factory()->create();

        $sermon = Sermon::factory()->create();
        $processing = LivestreamProcessingLog::factory()->create([
            'processing_id' => '12345678-1234-1234-1234-123456789def',
            'status' => 'completed',
            'sermon_id' => $sermon->id,
            'sermon_video_path' => 'sermons/1/video.mp4',
        ]);

        // Mock that a video file exists
        Storage::put('sermons/1/video.mp4', 'fake video content');

        $response = $this->actingAs($user)
            ->getJson('/api/livestreams/processing/12345678-1234-1234-1234-123456789def/status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'processing_id',
                'status',
                'sermon_video_path',
            ])
            ->assertJson([
                'processing_id' => '12345678-1234-1234-1234-123456789def',
                'status' => 'completed',
            ]);

        $this->assertNotNull($response->json('sermon_video_path'));
    }

    public function test_processing_status_current_step_detection()
    {
        $user = User::factory()->create();

        $processingId = \Illuminate\Support\Str::uuid();
        $processing = LivestreamProcessingLog::factory()->create([
            'processing_id' => $processingId,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/livestreams/processing/{$processingId}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'processing_id',
                'status',
                'current_step',
            ]);

        $currentStep = $response->json('current_step');
        $this->assertIsString($currentStep);
    }

    public function test_api_rate_limiting()
    {
        // Mock the service to ensure we can test rate limiting behavior
        $mockService = $this->createMock(\App\Services\VideoProcessingService::class);
        $mockResult = \App\Services\ProcessingResult::success(
            processingId: 'test-uuid-123',
            message: 'Livestream processing initiated successfully'
        );
        $mockService->method('processWithSegmentation')->willReturn($mockResult);
        $this->app->instance(\App\Services\VideoProcessingService::class, $mockService);

        $user = User::factory()->create();
        $videoFile = UploadedFile::fake()->create('test.mp4', 1000, 'video/mp4');

        $successCount = 0;
        $rateLimitedCount = 0;

        // Make multiple requests quickly to test rate limiting
        for ($i = 0; $i < 6; $i++) {
            $response = $this->actingAs($user)
                ->postJson('/api/livestreams/process', [
                    'video' => $videoFile,
                ]);

            if ($response->status() === 201) {
                $successCount++;
            } elseif ($response->status() === 429) {
                $rateLimitedCount++;
            }
        }

        // Verify that rate limiting is working - at least some requests should be rate limited
        $this->assertGreaterThan(0, $rateLimitedCount, 'Rate limiting should block some requests');
        $this->assertGreaterThan(0, $successCount, 'Some requests should succeed before rate limiting');
    }

    public function test_api_error_handling()
    {
        $user = User::factory()->create();

        // Test with corrupted or problematic file
        $corruptedFile = UploadedFile::fake()->create('corrupted.mp4', 1000, 'video/mp4');

        $response = $this->actingAs($user)
            ->postJson('/api/livestreams/process', [
                'video' => $corruptedFile,
            ]);

        // Should handle the upload, even if processing later fails
        $response->assertStatus(201);

        $processingId = $response->json('processing_id');
        $this->assertNotNull($processingId);
    }

    public function test_api_response_format_consistency()
    {
        $user = User::factory()->create();
        $videoFile = UploadedFile::fake()->create('consistent.mp4', 1000, 'video/mp4');

        $response = $this->actingAs($user)
            ->postJson('/api/livestreams/process', [
                'video' => $videoFile,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'processing_id',
                'status_url',
                'estimated_completion',
            ]);

        // Verify processing_id is a UUID
        $processingId = $response->json('processing_id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $processingId);

        // Verify status_url format
        $statusUrl = $response->json('status_url');
        $this->assertStringContainsString('/api/livestreams/processing/', $statusUrl);
        $this->assertStringContainsString('/status', $statusUrl);

        // Verify estimated_completion is a valid datetime
        $estimatedCompletion = $response->json('estimated_completion');
        $this->assertNotNull(\DateTime::createFromFormat(\DateTime::ISO8601, $estimatedCompletion));
    }

    public function test_api_handles_concurrent_uploads()
    {
        // Mock the service to avoid rate limiting issues
        $mockService = $this->createMock(\App\Services\VideoProcessingService::class);
        $mockService->method('processWithSegmentation')
            ->willReturnOnConsecutiveCalls(
                \App\Services\ProcessingResult::success(
                    processingId: 'uuid-1',
                    message: 'Processing started'
                ),
                \App\Services\ProcessingResult::success(
                    processingId: 'uuid-2',
                    message: 'Processing started'
                )
            );
        $this->app->instance(\App\Services\VideoProcessingService::class, $mockService);

        $user = User::factory()->create();

        $videoFile1 = UploadedFile::fake()->create('concurrent1.mp4', 1000, 'video/mp4');
        $videoFile2 = UploadedFile::fake()->create('concurrent2.mp4', 1000, 'video/mp4');

        // Simulate concurrent uploads
        $response1 = $this->actingAs($user)
            ->postJson('/api/livestreams/process', ['video' => $videoFile1]);

        $response2 = $this->actingAs($user)
            ->postJson('/api/livestreams/process', ['video' => $videoFile2]);

        // Allow either success or rate limiting - both are acceptable behaviors
        $this->assertContains($response1->status(), [201, 429]);
        $this->assertContains($response2->status(), [201, 429]);

        // If both succeeded, verify different processing IDs
        if ($response1->status() === 201 && $response2->status() === 201) {
            $processingId1 = $response1->json('processing_id');
            $processingId2 = $response2->json('processing_id');

            $this->assertNotEquals($processingId1, $processingId2);
        }
    }
}
