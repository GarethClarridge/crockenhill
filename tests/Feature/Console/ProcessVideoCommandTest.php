<?php

namespace Tests\Feature\Console;

use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\VideoExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessVideoCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_sermon_from_livestream_segments(): void
    {
        Storage::fake('public');

        $processingId = 'test-id';
        $log = MediaProcessingLog::factory()->create([
            'processing_id' => $processingId,
            'source_file_path' => 'livestream/source.mp4',
        ]);

        Storage::disk('local')->put('livestream/source.mp4', 'content');

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'classification' => 'speech',
            'duration' => 1200,
            'start_time' => 100,
            'end_time' => 1300,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('/tmp/segment.mp4');
        $mockExtractor->expects($this->once())
            ->method('extractAudio')
            ->willReturn('sermons/test.mp3');

        $this->app->instance(VideoExtractionService::class, $mockExtractor);

        // Simulate temp segment file
        file_put_contents('/tmp/segment.mp4', 'segment content');

        $this->artisan('livestream:create-sermon', ['processing_id' => $processingId])
            ->assertExitCode(0)
            ->expectsOutputToContain('Created sermon record with ID');

        $this->assertDatabaseHas('sermons', [
            'livestream_processing_id' => $processingId,
            'source_type' => 'livestream',
        ]);

        $sermon = Sermon::where('livestream_processing_id', $processingId)->first();
        Storage::disk('public')->assertExists($sermon->video_file_path);

        // Cleanup temp file
        if (file_exists('/tmp/segment.mp4')) {
            unlink('/tmp/segment.mp4');
        }
    }

    #[Test]
    public function it_fails_if_processing_log_not_found(): void
    {
        $this->artisan('livestream:create-sermon', ['processing_id' => 'non-existent'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Processing log not found');
    }

    #[Test]
    public function it_fails_if_no_speech_segment_found(): void
    {
        $processingId = 'test-id';
        MediaProcessingLog::factory()->create(['processing_id' => $processingId]);

        $this->artisan('livestream:create-sermon', ['processing_id' => $processingId])
            ->assertExitCode(1)
            ->expectsOutputToContain('No sermon segment found');
    }
}
