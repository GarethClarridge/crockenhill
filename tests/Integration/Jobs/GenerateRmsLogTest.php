<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\GenerateRmsLog;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
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
        $this->assertEquals(7200, $job->timeout);
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

    /**
     * Recovering the 2026-09-05 outage re-runs 171 runs from the head of the
     * chain. RMS generation is the second most expensive step in it, and the
     * recording it describes has not changed — so a retry holding a parseable
     * log should adopt it rather than pay for it twice.
     */
    #[Test]
    public function it_reuses_a_recorded_rms_log_rather_than_regenerating_it(): void
    {
        Storage::fake('transcripts');
        config(['media-processing.storage.transcript_disk' => 'transcripts']);

        $rmsPath = 'service-transcripts/2026-09-05/morning.rms.json';
        Storage::disk('transcripts')->put($rmsPath, implode("\n", [
            'frame:0    pts_time:0',
            'lavfi.astats.Overall.RMS_level=-35.5',
        ]));

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => 'irrelevant-because-nothing-is-read.mp4',
            'file_size' => 1024,
            'rms_log_path' => $rmsPath,
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->never())->method('generateRmsLog');

        (new GenerateRmsLog($log, mayReuseRecordedRmsLog: true))->handle($mockService);

        $log->refresh();
        $this->assertSame($rmsPath, $log->rms_log_path, 'Reuse must not disturb the recorded path.');
        $this->assertSame(
            ProcessingStatus::Skipped,
            SermonProcessingStep::query()
                ->where('processing_id', $log->processing_id)
                ->where('step', 'rms_generation')
                ->value('status'),
        );
    }

    /**
     * The outage that makes reuse worth having also truncates files, so an
     * unparseable log must send the step back through generation rather than be
     * adopted as evidence.
     */
    #[Test]
    public function it_regenerates_when_the_recorded_rms_log_holds_no_frames(): void
    {
        Storage::fake('transcripts');
        config(['media-processing.storage.transcript_disk' => 'transcripts']);
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['media-processing.types.livestream.max_file_size' => 2147483648]);

        Storage::disk('transcripts')->put('service-transcripts/2026-09-05/morning.rms.json', '');

        $videoFilename = 'test-livestream-'.uniqid().'.mp4';
        $absolutePath = storage_path('app').'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $videoFilename,
            'file_size' => 1024,
            'rms_log_path' => 'service-transcripts/2026-09-05/morning.rms.json',
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->once())
            ->method('generateRmsLog')
            ->willReturnCallback(function () use ($log): string {
                $path = 'rms-logs/'.$log->processing_id.'.json';
                Storage::disk('local')->put($path, '{"rms":-20}');

                return $path;
            });

        (new GenerateRmsLog($log, mayReuseRecordedRmsLog: true))->handle($mockService);

        @unlink($absolutePath);
    }

    /**
     * Reuse is the caller's decision, never inferred from the artifact being
     * there. A run dispatched deliberately rather than resumed is asking for
     * the measurement to be taken again, and must get it.
     */
    #[Test]
    public function it_regenerates_a_recorded_rms_log_when_not_resuming(): void
    {
        Storage::fake('transcripts');
        config(['media-processing.storage.transcript_disk' => 'transcripts']);
        config(['media-processing.storage.temp_disk' => 'local']);
        config(['media-processing.types.livestream.max_file_size' => 2147483648]);

        $rmsPath = 'service-transcripts/2026-09-05/morning.rms.json';
        Storage::disk('transcripts')->put($rmsPath, implode("\n", [
            'frame:0    pts_time:0',
            'lavfi.astats.Overall.RMS_level=-35.5',
        ]));

        $videoFilename = 'test-livestream-'.uniqid().'.mp4';
        $absolutePath = storage_path('app').'/'.$videoFilename;
        file_put_contents($absolutePath, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $videoFilename,
            'file_size' => 1024,
            'rms_log_path' => $rmsPath,
        ]);

        $mockService = $this->createMock(VideoSegmentationService::class);
        $mockService->expects($this->once())
            ->method('generateRmsLog')
            ->willReturnCallback(function () use ($log): string {
                $path = 'rms-logs/'.$log->processing_id.'.json';
                Storage::disk('local')->put($path, '{"rms":-20}');

                return $path;
            });

        (new GenerateRmsLog($log))->handle($mockService);

        @unlink($absolutePath);
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
            ->willReturnCallback(function () use ($log): string {
                $path = 'rms-logs/'.$log->processing_id.'.json';
                Storage::disk('local')->put($path, '{"rms":-20}');

                return $path;
            });

        Log::shouldReceive('info')->atLeast()->once();

        $job = new GenerateRmsLog($log);
        $job->handle($mockService);

        $log->refresh();
        $this->assertEquals(
            'service-transcripts/unknown-date/other-'.$log->processing_id.'.rms.json',
            $log->rms_log_path,
        );
        $this->assertSame(
            ProcessingStatus::Completed,
            SermonProcessingStep::query()
                ->where('processing_id', $log->processing_id)
                ->where('step', 'rms_generation')
                ->value('status'),
        );

        /**
         * The archive is a copy. The original is named after a fresh UUID that
         * no record points at, so nothing could ever find it again — the
         * historic-video pilot left fifteen of them on the drive whose free
         * space gates the bulk run.
         */
        $this->assertFalse(
            Storage::disk('local')->exists('rms-logs/'.$log->processing_id.'.json'),
            'The RMS working copy outlived the run that made it.',
        );

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
        $this->assertSame(
            ProcessingStatus::Failed,
            SermonProcessingStep::query()
                ->where('processing_id', $log->processing_id)
                ->where('step', 'rms_generation')
                ->value('status'),
        );
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

        $this->assertSame(
            ProcessingStatus::Skipped,
            SermonProcessingStep::query()
                ->where('processing_id', $log->processing_id)
                ->where('step', 'rms_generation')
                ->value('status'),
        );
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
            ->willReturnCallback(function () use ($log): string {
                $path = 'rms-logs/'.$log->processing_id.'.json';
                Storage::disk('local')->put($path, '{"rms":-20}');

                return $path;
            });

        Log::shouldReceive('info')->atLeast()->once();

        $job = new GenerateRmsLog($log);
        $job->handle($mockService);

        $log->refresh();
        $this->assertEquals(
            'service-transcripts/unknown-date/other-'.$log->processing_id.'.rms.json',
            $log->rms_log_path,
        );

        @unlink($absolutePath);
    }
}
