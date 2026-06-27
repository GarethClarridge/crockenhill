<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\ExtractAudioFromVideo;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractAudioFromVideoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_throws_when_stored_file_path_is_missing(): void
    {
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'stored_file_path' => null,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractAudioFromVideo($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No stored file path found in processing log');

        $job->handle($mockExtractor);
    }

    #[Test]
    public function it_throws_when_video_file_does_not_exist(): void
    {
        Storage::fake('local');

        config(['filesystems.default' => 'local']);

        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'stored_file_path' => 'temp/nonexistent-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractAudioFromVideo($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Video file not found at path/');

        $job->handle($mockExtractor);
    }

    #[Test]
    public function it_updates_processing_log_with_audio_path_on_success(): void
    {
        // Create a temporary video file
        $tempDir = storage_path('app');
        $videoFilename = 'test-video-'.uniqid().'.mp4';
        $videoRelPath = $videoFilename;
        $absolutePath = $tempDir.'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        config(['filesystems.default' => 'local']);

        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'stored_file_path' => $videoRelPath,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'sermons/audio/extracted.mp3',
                'full_path' => '/storage/app/sermons/audio/extracted.mp3',
                'original_size' => 1048576,
                'final_size' => 524288,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once(); // For getVideoDuration fallback

        $job = new ExtractAudioFromVideo($log);
        $job->handle($mockExtractor);

        $log->refresh();
        $this->assertEquals('sermons/audio/extracted.mp3', $log->audio_file_path);
        $this->assertEquals('audio_extraction_complete', $log->current_step);
        $this->assertEquals('processing', $log->status->value);

        @unlink($absolutePath);
    }

    #[Test]
    public function it_stores_compression_metadata_in_processing_log(): void
    {
        $tempDir = storage_path('app');
        $videoFilename = 'test-video-'.uniqid().'.mp4';
        $absolutePath = $tempDir.'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        config(['filesystems.default' => 'local']);

        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'stored_file_path' => $videoFilename,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'sermons/audio/extracted.mp3',
                'full_path' => '/absolute/path/extracted.mp3',
                'original_size' => 2097152,
                'final_size' => 524288,
                'compression_applied' => true,
                'compression_ratio' => 0.25,
                'valid_for_transcription' => true,
            ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $job = new ExtractAudioFromVideo($log);
        $job->handle($mockExtractor);

        $log->refresh();
        $this->assertArrayHasKey('audio_compression', $log->processing_metadata);
        $compression = $log->processing_metadata['audio_compression'];
        $this->assertEquals(2.0, $compression['original_size_mb']);
        $this->assertEquals(0.5, $compression['final_size_mb']);
        $this->assertTrue($compression['compression_applied']);
        $this->assertEquals(0.25, $compression['compression_ratio']);

        @unlink($absolutePath);
    }

    #[Test]
    public function it_marks_processing_log_as_failed_on_exception(): void
    {
        $tempDir = storage_path('app');
        $videoFilename = 'test-video-'.uniqid().'.mp4';
        $absolutePath = $tempDir.'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        config(['filesystems.default' => 'local']);

        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'stored_file_path' => $videoFilename,
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willThrowException(new \Exception('FFmpeg extraction failed'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractAudioFromVideo($log);

        try {
            $job->handle($mockExtractor);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('FFmpeg extraction failed', $e->getMessage());
        } finally {
            @unlink($absolutePath);
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Audio extraction failed', $log->error_message);
    }

    #[Test]
    public function failed_method_marks_processing_log_as_failed(): void
    {
        $log = MediaProcessingLog::factory()->video()->processing()->create();

        $job = new ExtractAudioFromVideo($log);
        $job->failed(new \Exception('Job failed permanently'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Audio extraction job failed', $log->error_message);
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create([
            'stored_file_path' => 'temp/some-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractOptimizedAudio');

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'job skipped: processing cancelled'));

        $job = new ExtractAudioFromVideo($log);
        $job->handle($mockExtractor);
    }

    #[Test]
    public function mark_as_failed_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create();

        $result = app(MediaProcessingRunTransitionService::class)
            ->markAsFailed($log, 'Audio extraction failed: something went wrong');

        $this->assertFalse($result);
        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'markAsFailed must not overwrite a cancelled run');
    }

    #[Test]
    public function cancelled_run_is_not_overwritten_to_failed_by_failed_method(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create();

        $job = new ExtractAudioFromVideo($log);
        $job->failed(new \Exception('Queue exhausted retries'));

        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'Cancelled run must not be overwritten to failed by failed()');
    }
}
