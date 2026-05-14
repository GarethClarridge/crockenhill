<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\CleanupTemporaryFiles;
use App\Models\MediaProcessingLog;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupTemporaryFilesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_collects_source_file_path_for_cleanup(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'temp/source-audio.mp3',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/source-audio.mp3', $files);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    public function it_collects_metadata_temp_paths_for_cleanup(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => null,
            'processing_metadata' => [
                'extracted_segment_path' => 'temp/segment.mp4',
                'extracted_audio_path' => 'temp/audio.mp3',
                'temp_video_path' => 'temp/video.mp4',
            ],
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/segment.mp4', $files)
                    && in_array('temp/audio.mp3', $files)
                    && in_array('temp/video.mp4', $files);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    public function it_includes_stored_file_for_video_type_in_temp_directory(): void
    {
        // stored_file_path is an alias for source_file_path
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'source_file_path' => 'temp/stored-video.mp4',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/stored-video.mp4', $files);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);
    }

    #[Test]
    public function it_does_not_include_stored_file_for_video_outside_temp(): void
    {
        // Note: stored_file_path is an accessor alias for source_file_path
        // so when the video check reads stored_file_path it reads source_file_path
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'source_file_path' => 'sermons/permanent-video.mp4',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                // source_file_path is always collected (line 37-39),
                // but the video temp/ check should NOT add it a second time
                return count(array_keys($files, 'sermons/permanent-video.mp4')) === 1;
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);
    }

    #[Test]
    public function it_handles_empty_metadata_gracefully(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => null,
        ]);

        // Ensure no metadata is set
        $log->update(['processing_metadata' => null]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with([]);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    public function it_marks_as_completed_even_on_cleanup_error(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'temp/some-file.mp3',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->willThrowException(new \Exception('Cleanup failed'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals('completed', $log->status->value);
    }

    #[Test]
    public function it_preserves_notification_failure_context_when_marking_completed(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'source_file_path' => 'temp/source-audio.mp3',
            'current_step' => 'notification_failed',
            'error_message' => 'Notification failed: SMTP transport unavailable',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(function (array $files) {
                return in_array('temp/source-audio.mp3', $files, true);
            }));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();

        $this->assertEquals('completed', $log->status->value);
        $this->assertSame('notification_failed', $log->current_step);
        $this->assertSame(
            'Notification failed: SMTP transport unavailable',
            $log->error_message
        );
    }

    #[Test]
    public function it_runs_cleanup_but_does_not_overwrite_cancelled_status(): void
    {
        $log = MediaProcessingLog::factory()->audio()->cancelled()->create([
            'source_file_path' => 'temp/source-audio.mp3',
        ]);

        $mockStorage = $this->createMock(VideoStorageService::class);
        $mockStorage->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with($this->callback(fn (array $files) => in_array('temp/source-audio.mp3', $files, true)));

        Log::shouldReceive('info')->atLeast()->once();

        $job = new CleanupTemporaryFiles($log);
        $job->handle($mockStorage);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::Cancelled, $log->status);
        $this->assertEquals('cancelled', $log->current_step);
        $this->assertNull($log->completed_at);
    }

    #[Test]
    public function it_has_correct_job_configuration(): void
    {
        $log = MediaProcessingLog::factory()->create();

        $job = new CleanupTemporaryFiles($log);

        $this->assertEquals(1, $job->tries);
        $this->assertEquals(300, $job->timeout);
    }
}
