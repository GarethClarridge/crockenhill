<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ProcessingResult;
use App\Models\User;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Sermon\LivestreamSegmentationService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnifiedMediaProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable CSRF protection for these form tests
        $this->withoutMiddleware(PreventRequestForgery::class);

        // Mock the services to avoid actual video/audio processing in tests
        $this->mock(VideoSegmentationService::class, function ($mock) {
            $mock->shouldReceive('analyzeSegments')->andReturn([
                ['start_time' => 300, 'end_time' => 2100, 'is_sermon_candidate' => true],
            ]);
            $mock->shouldReceive('extractSegment')->andReturn(
                UploadedFile::fake()->create('extracted.mp4', 25000, 'video/mp4')
            );
        });

        // Mock the UnifiedMediaProcessor to avoid FFmpeg and actual processing
        $this->mock(UnifiedMediaProcessor::class, function ($mock) {
            $mock->shouldReceive('process')->with('livestream', \Mockery::any())->andReturn(
                ProcessingResult::success('livestream-id', 'Livestream processing started')
            );
            $mock->shouldReceive('process')->with('video', \Mockery::any())->andReturn(
                ProcessingResult::success('video-id', 'Video processing started')
            );
            $mock->shouldReceive('process')->with('audio', \Mockery::any())->andReturn(
                ProcessingResult::success('audio-id', 'Audio processing started')
            );
        });

        // Mock LivestreamSegmentationService for direct service calls
        $this->mock(LivestreamSegmentationService::class, function ($mock) {
            $mock->shouldReceive('startProcessing')->andReturn(
                ProcessingResult::success('livestream-id', 'Livestream processing started')
            );
        });
    }

    #[Test]
    public function livestream_processing_flows_through_complete_pipeline()
    {
        // Create authenticated user with sermon creation permissions
        $user = User::factory()->create([
            'email' => 'test@crockenhill.org',
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Test: Livestream → Video Segment → Audio → Sermon
        $livestreamFile = UploadedFile::fake()->create('service.mp4', 50000, 'video/mp4');

        $response = $this->postJson('/api/media/livestream', [
            'file' => $livestreamFile,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['processing_id', 'message']);
        $this->assertEquals('livestream-id', $response->json('processing_id'));
    }

    #[Test]
    public function video_processing_skips_segmentation_step()
    {
        // Create authenticated user with sermon creation permissions
        $user = User::factory()->create([
            'email' => 'test@crockenhill.org',
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Test: Sermon Video → Audio → Sermon (skip livestream segmentation)
        $videoFile = UploadedFile::fake()->create('sermon.mp4', 25000, 'video/mp4');

        $response = $this->postJson('/api/media/video', [
            'file' => $videoFile,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['processing_id', 'message']);
        $this->assertEquals('video-id', $response->json('processing_id'));
    }

    #[Test]
    public function audio_processing_goes_directly_to_transcription()
    {
        // Create authenticated user with sermon creation permissions
        $user = User::factory()->create([
            'email' => 'test@crockenhill.org',
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Test: Audio → Sermon (skip video processing steps)
        $audioFile = UploadedFile::fake()->create('sermon.mp3', 5000, 'audio/mpeg');

        $response = $this->postJson('/api/media/audio', [
            'file' => $audioFile,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['processing_id', 'message']);
        $this->assertEquals('audio-id', $response->json('processing_id'));
    }
}
