<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\GenerateRmsLog;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoSegmentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateRmsLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_correct_retry_configuration(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->make();
        $job = new GenerateRmsLog($log);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(3600, $job->timeout);
    }

    #[Test]
    public function it_throws_when_video_file_not_found(): void
    {
        Storage::fake('local');
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['media-processing.file_wait_retry_delay_seconds' => 0]); // No sleep in tests

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => 'nonexistent-livestream.mp4',
            'file_size' => 1024,
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once(); // For file waiting attempts
        Log::shouldReceive('error')->atLeast()->once();

        $job = new GenerateRmsLog($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Video file not found after waiting/');

        $job->handle($mockService);
    }

    #[Test]
    public function it_generates_rms_log_and_updates_processing_log(): void
    {
        // Create a temp video file
        $tempDir = storage_path('app');
        $videoFilename = 'test-livestream-'.uniqid().'.mp4';
        $absolutePath = $tempDir.'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        config(['media-processing.storage.temp_disk' => 'local']);
        config(['media-processing.types.livestream.max_file_size' => 2147483648]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $videoFilename,
            'file_size' => 1024,
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->once())
            ->method('generateRmsLog')
            ->willReturn('rms-logs/'.$log->processing_id.'.json');

        Log::shouldReceive('info')->atLeast()->once();

        $job = new GenerateRmsLog($log);
        $job->handle($mockService);

        $log->refresh();
        $this->assertEquals('rms-logs/'.$log->processing_id.'.json', $log->rms_log_path);

        @unlink($absolutePath);
    }

    #[Test]
    public function it_throws_when_file_size_exceeds_maximum(): void
    {
        $tempDir = storage_path('app');
        $videoFilename = 'test-large-'.uniqid().'.mp4';
        $absolutePath = $tempDir.'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        config(['media-processing.storage.temp_disk' => 'local']);
        config(['media-processing.types.livestream.max_file_size' => 1024]); // 1KB limit

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $videoFilename,
            'file_size' => 2048, // 2KB - exceeds limit
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->never())
            ->method('generateRmsLog');

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new GenerateRmsLog($log);

        try {
            $job->handle($mockService);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('File size exceeds maximum', $e->getMessage());
        } finally {
            @unlink($absolutePath);
        }
    }

    #[Test]
    public function it_marks_processing_log_as_failed_on_segmentation_exception(): void
    {
        $tempDir = storage_path('app');
        $videoFilename = 'test-fail-'.uniqid().'.mp4';
        $absolutePath = $tempDir.'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        config(['media-processing.storage.temp_disk' => 'local']);
        config(['media-processing.types.livestream.max_file_size' => 2147483648]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $videoFilename,
            'file_size' => 1024,
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->once())
            ->method('generateRmsLog')
            ->willThrowException(new \Exception('FFmpeg process failed'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->atLeast()->once();

        $job = new GenerateRmsLog($log);

        try {
            $job->handle($mockService);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('FFmpeg process failed', $e->getMessage());
        } finally {
            @unlink($absolutePath);
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('RMS log generation failed', $log->error_message);
    }

    #[Test]
    public function failed_method_marks_processing_log_as_failed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        Log::shouldReceive('error')->once();

        $job = new GenerateRmsLog($log);
        $job->failed(new \Exception('Permanent failure after retries'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('RMS log generation failed after', $log->error_message);
    }

    #[Test]
    public function it_skips_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['status' => 'cancelled']);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->never())->method('generateRmsLog');

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'job skipped: processing cancelled'));

        $job = new GenerateRmsLog($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_succeeds_when_file_exists_immediately(): void
    {
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['media-processing.types.livestream.max_file_size' => 2147483648]);

        $tempDir = storage_path('app');
        $videoFilename = 'test-immediate-'.uniqid().'.mp4';
        $absolutePath = $tempDir.'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $videoFilename,
            'file_size' => 1024,
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->once())
            ->method('generateRmsLog')
            ->willReturn('rms-logs/'.$log->processing_id.'.json');

        Log::shouldReceive('info')->atLeast()->once();

        $job = new GenerateRmsLog($log);
        $job->handle($mockService);

        $log->refresh();
        $this->assertEquals('rms-logs/'.$log->processing_id.'.json', $log->rms_log_path);

        @unlink($absolutePath);
    }
}
