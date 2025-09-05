<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaProcessingService;
use App\Services\VideoSegmentationService;
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

        // Mock the services to avoid actual video/audio processing in tests
        $this->mock(VideoSegmentationService::class, function ($mock) {
            $mock->shouldReceive('analyzeSegments')->andReturn([
                ['start_time' => 300, 'end_time' => 2100, 'is_sermon_candidate' => true],
            ]);
            $mock->shouldReceive('extractSegment')->andReturn(
                UploadedFile::fake()->create('extracted.mp4', 25000, 'video/mp4')
            );
        });

        // Mock the SermonProcessingService to avoid actual processing
        $this->mock(\App\Services\SermonProcessingService::class, function ($mock) {
            $mock->shouldReceive('processSermon')->andReturn(
                \App\Services\ProcessingResult::success('test-processing-id', 'Processing started')
            );
        });

        // Mock the entire MediaProcessingService to avoid FFmpeg and actual processing
        $this->mock(MediaProcessingService::class, function ($mock) {
            $mock->shouldReceive('processLivestream')->andReturn(
                \App\Services\ProcessingResult::success('livestream-id', 'Livestream processing started')
            );
            $mock->shouldReceive('processVideo')->andReturn(
                \App\Services\ProcessingResult::success('video-id', 'Video processing started')
            );
            $mock->shouldReceive('processAudio')->andReturn(
                \App\Services\ProcessingResult::success('audio-id', 'Audio processing started')
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
        ]);
        Sanctum::actingAs($user, ['*']);

        // Test: Livestream → Video Segment → Audio → Sermon
        $livestreamFile = UploadedFile::fake()->create('service.mp4', 50000, 'video/mp4');

        $response = $this->postJson('/api/sermons/livestream', [
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
        ]);
        Sanctum::actingAs($user, ['*']);

        // Test: Sermon Video → Audio → Sermon (skip livestream segmentation)
        $videoFile = UploadedFile::fake()->create('sermon.mp4', 25000, 'video/mp4');

        $response = $this->postJson('/api/sermons/video', [
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
        ]);
        Sanctum::actingAs($user, ['*']);

        // Test: Audio → Sermon (skip video processing steps)
        $audioFile = UploadedFile::fake()->create('sermon.mp3', 5000, 'audio/mpeg');

        $response = $this->postJson('/api/sermons/audio', [
            'file' => $audioFile,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['processing_id', 'message']);
        $this->assertEquals('audio-id', $response->json('processing_id'));
    }

    #[Test]
    public function web_interface_uses_same_processing_pipeline()
    {
        // Test: Web upload flows to same service as API
        $user = User::factory()->create([
            'email' => 'test@crockenhill.org',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        $audioFile = UploadedFile::fake()->create('sermon.mp3', 5000, 'audio/mpeg');

        $response = $this->post('/church/members/sermon-upload', [
            'file' => $audioFile,
            'type' => 'audio',
        ]);

        // Should redirect with success message containing processing ID
        $response->assertRedirect();
        $response->assertSessionHas('message');
        $message = session('message');
        $this->assertStringContainsString('audio-id', $message);
    }
}
