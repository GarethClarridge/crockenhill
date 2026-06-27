<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\ValidateVideoFile;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\MediaValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidateVideoFileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_validates_a_valid_video_file(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        // Create a temp video file with a valid MP4 header
        $filePath = 'videos/test-video.mp4';
        $mp4Header = "\x00\x00\x00\x1C\x66\x74\x79\x70\x69\x73\x6F\x6D";
        Storage::disk('local')->put($filePath, $mp4Header.str_repeat("\x00", 1024));

        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'stored_file_path' => $filePath,
        ]);

        Log::shouldReceive('info')->atLeast()->once();

        $job = new ValidateVideoFile($log);
        $job->handle($this->app->make(MediaValidationService::class));

        $log->refresh();
        $this->assertEquals('video_validation_complete', $log->current_step);
        $this->assertEquals('processing', $log->status->value);
    }

    #[Test]
    public function it_fails_when_stored_file_path_is_missing(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'stored_file_path' => null,
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateVideoFile($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No stored file path found in processing log');

        $job->handle($this->app->make(MediaValidationService::class));
    }

    #[Test]
    public function it_fails_when_file_does_not_exist(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'stored_file_path' => 'videos/nonexistent.mp4',
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateVideoFile($log);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Video file not found at path');

        $job->handle($this->app->make(MediaValidationService::class));
    }

    #[Test]
    public function it_fails_when_file_exceeds_max_size(): void
    {
        config(['filesystems.default' => 'local']);
        config(['media-processing.types.video.max_file_size' => 100]); // 100 bytes

        // Create a real temp file that exceeds the limit
        $tempDir = storage_path('app/videos');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir.'/oversized.mp4';
        file_put_contents($tempFile, str_repeat('x', 200));

        // Point the storage disk path to include the real file
        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'stored_file_path' => 'videos/oversized.mp4',
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateVideoFile($log);

        try {
            $job->handle($this->app->make(MediaValidationService::class));
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('exceeds maximum limit', $e->getMessage());
        } finally {
            @unlink($tempFile);
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Video validation failed', $log->error_message);
    }

    #[Test]
    public function it_fails_for_unsupported_mime_type(): void
    {
        config(['filesystems.default' => 'local']);

        // Create a real temp file with text content (text/plain MIME)
        $tempDir = storage_path('app/videos');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir.'/bad-type.txt';
        file_put_contents($tempFile, 'This is plain text, not a video file.');

        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'stored_file_path' => 'videos/bad-type.txt',
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateVideoFile($log);

        try {
            $job->handle($this->app->make(MediaValidationService::class));
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Invalid file type', $e->getMessage());
        } finally {
            @unlink($tempFile);
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
    }

    #[Test]
    public function it_updates_processing_log_on_failure(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'stored_file_path' => null,
        ]);

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('error')->once();

        $job = new ValidateVideoFile($log);

        try {
            $job->handle($this->app->make(MediaValidationService::class));
        } catch (\Exception) {
            // Expected
        }

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertStringContainsString('Video validation failed', $log->error_message);
    }

    #[Test]
    public function failed_method_updates_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->video()->pending()->create();

        $job = new ValidateVideoFile($log);
        $job->failed(new \Exception('Something went wrong'));

        $log->refresh();
        $this->assertEquals('failed', $log->status->value);
        $this->assertEquals('Video validation job failed: Something went wrong', $log->error_message);
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create([
            'stored_file_path' => 'videos/some-video.mp4',
        ]);

        $mockValidation = $this->createMock(MediaValidationService::class);
        $mockValidation->expects($this->never())->method('validateLocalFile');

        Log::shouldReceive('info')->once()->withArgs(fn ($msg) => str_contains($msg, 'job skipped: processing cancelled'));

        $job = new ValidateVideoFile($log);
        $job->handle($mockValidation);
    }

    #[Test]
    public function mark_as_failed_does_not_overwrite_a_cancelled_run(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create();

        $result = app(MediaProcessingRunTransitionService::class)
            ->markAsFailed($log, 'Video validation failed: something went wrong');

        $this->assertFalse($result);
        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'markAsFailed must not overwrite a cancelled run');
    }

    #[Test]
    public function cancelled_run_is_not_overwritten_to_failed_by_failed_method(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create();

        $job = new ValidateVideoFile($log);
        $job->failed(new \Exception('Queue exhausted retries'));

        $log->refresh();
        $this->assertEquals('cancelled', $log->status->value, 'Cancelled run must not be overwritten to failed by failed()');
    }
}
