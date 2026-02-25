<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ExtractSermon;
use App\Models\MediaProcessingLog;
use App\Services\StorageAdapterHelper;
use App\Services\VideoExtractionService;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractSermonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_correct_job_configuration(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $job = new ExtractSermon($log);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(3600, $job->timeout);
    }

    #[Test]
    public function it_skips_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['status' => 'cancelled']);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->never())->method('extractSegmentAsFile');

        $mockStorage = $this->createMock(VideoStorageService::class);

        Log::shouldReceive('info')->once()->with('ExtractSermon job skipped: processing cancelled', \Mockery::any());

        $job = new ExtractSermon($log);
        $job->handle($mockExtractor, $mockStorage, app(StorageAdapterHelper::class));
    }

    #[Test]
    public function it_throws_when_sermon_times_missing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => null,
            'sermon_end_time' => null,
            'source_file_path' => 'livestreams/test.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockStorage = $this->createMock(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sermon segment times not found');

        $job->handle($mockExtractor, $mockStorage, app(StorageAdapterHelper::class));
    }

    #[Test]
    public function it_updates_processing_log_with_extraction_data(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        // Create a temporary video file
        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/test-video.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        // Create a temporary extracted audio file for verification
        $extractedDir = storage_path('app/extracted');
        if (! is_dir($extractedDir)) {
            mkdir($extractedDir, 0755, true);
        }
        $extractedAudioFile = $extractedDir.'/sermon-audio.mp3';
        file_put_contents($extractedAudioFile, str_repeat("\xFF\xFB", 512));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/test-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('extracted/sermon-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/sermon-audio.mp3',
                'full_path' => $extractedAudioFile,
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => true,
                'compression_ratio' => 0.5,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createMock(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new ExtractSermon($log);
        $job->handle($mockExtractor, $mockStorage, app(StorageAdapterHelper::class));

        $log->refresh();
        $this->assertEquals('extraction_complete', $log->current_step);
        $this->assertEquals('extracted/sermon-video.mp4', $log->video_file_path);
        $this->assertEquals('extracted/sermon-audio.mp3', $log->audio_file_path);
        $this->assertNotNull($log->processing_metadata);
        $this->assertArrayHasKey('audio_compression', $log->processing_metadata);
        $this->assertTrue($log->processing_metadata['audio_compression']['compression_applied']);

        @unlink($videoFile);
        @unlink($extractedAudioFile);
    }

    #[Test]
    public function it_marks_as_failed_when_extraction_throws(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/fail-video.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/fail-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willThrowException(new \Exception('FFmpeg segfault'));

        $mockStorage = $this->createMock(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);

        try {
            $job->handle($mockExtractor, $mockStorage, app(StorageAdapterHelper::class));
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('FFmpeg segfault', $e->getMessage());
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Sermon extraction failed', $log->error_message);

        @unlink($videoFile);
    }

    #[Test]
    public function it_throws_when_audio_file_does_not_exist_after_extraction(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['filesystems.disks.local.driver' => 'local']);

        $tempDir = storage_path('app/livestreams');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $videoFile = $tempDir.'/verify-video.mp4';
        file_put_contents($videoFile, str_repeat("\x00", 1024));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 2100.0,
            'source_file_path' => 'livestreams/verify-video.mp4',
        ]);

        $mockExtractor = $this->createMock(VideoExtractionService::class);
        $mockExtractor->expects($this->once())
            ->method('extractSegmentAsFile')
            ->willReturn('extracted/sermon-video.mp4');

        $mockExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'extracted/ghost-audio.mp3',
                'full_path' => '/nonexistent/path/ghost-audio.mp3',
                'original_size' => 10485760,
                'final_size' => 5242880,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $mockStorage = $this->createMock(VideoStorageService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);

        try {
            $job->handle($mockExtractor, $mockStorage, app(StorageAdapterHelper::class));
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('file does not exist', $e->getMessage());
        }

        @unlink($videoFile);
    }

    #[Test]
    public function failed_method_marks_processing_log_as_failed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        Log::shouldReceive('error')->once();

        $job = new ExtractSermon($log);
        $job->failed(new \Exception('Extraction timeout'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Sermon extraction failed after', $log->error_message);
    }
}
