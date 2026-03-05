<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\ThumbnailResult;
use App\Jobs\GenerateThumbnail;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThumbnailErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('sermon_disk');
        Queue::fake();

        config([
            'media-processing.storage.sermon_disk' => 'public',
        ]);
    }

    #[Test]
    public function thumbnail_generation_errors_do_not_break_main_processing(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Error Test Sermon',
            'video_file_path' => 'sermons/error-test/video.mp4',
        ]);
        $log = $this->createVideoProcessingLog($sermon);

        Storage::disk('public')->put('sermons/error-test/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willThrowException(new \Exception('Thumbnail generation failed'));

        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation job encountered an error',
            \Mockery::on(function (array $context) use ($sermon): bool {
                return $context['sermon_id'] === $sermon->id &&
                       $context['video_path'] === 'sermons/error-test/video.mp4' &&
                       $context['error'] === 'Thumbnail generation failed';
            })
        );

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
        $this->assertNull($sermon->thumbnail_generated_at);
    }

    #[Test]
    public function thumbnail_service_handles_ffmpeg_errors_gracefully(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'FFmpeg Error Test',
        ]);

        // Create a file that's not a valid video
        $tempFile = tempnam(sys_get_temp_dir(), 'invalid_video');
        file_put_contents($tempFile, 'This is not a video file');

        $service = app(ThumbnailGenerationService::class);

        $result = $service->generateThumbnail($sermon, $tempFile);

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_storage_errors(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Storage Error Test',
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        $service = app(ThumbnailGenerationService::class);

        $result = $service->generateThumbnail($sermon, $tempFile);

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_memory_exhaustion(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Memory Test Sermon',
            'video_file_path' => 'sermons/memory-test/video.mp4',
        ]);
        $log = $this->createVideoProcessingLog($sermon);

        Storage::disk('public')->put('sermons/memory-test/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willReturn(ThumbnailResult::skipped('Simulated memory exhaustion'));

        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation skipped',
            \Mockery::on(function (array $context): bool {
                return str_contains($context['reason'], 'memory');
            })
        );

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
    }

    #[Test]
    public function thumbnail_generation_handles_timeout_scenarios(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Timeout Test Sermon',
            'video_file_path' => 'sermons/timeout-test/video.mp4',
        ]);
        $log = $this->createVideoProcessingLog($sermon);

        Storage::disk('public')->put('sermons/timeout-test/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willThrowException(new \Exception('Maximum execution time exceeded'));

        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation job encountered an error',
            \Mockery::on(function (array $context): bool {
                return str_contains($context['error'], 'execution time');
            })
        );

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
    }

    #[Test]
    public function thumbnail_generation_handles_corrupted_video_files(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Corrupted Video Test',
        ]);

        // Create a corrupted video file (just random bytes)
        $tempFile = tempnam(sys_get_temp_dir(), 'corrupted_video');
        file_put_contents($tempFile, random_bytes(1024));

        $service = app(ThumbnailGenerationService::class);

        $result = $service->generateThumbnail($sermon, $tempFile);

        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_permission_errors(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Permission Error Test',
            'video_file_path' => 'sermons/permission-test/video.mp4',
        ]);
        $log = $this->createVideoProcessingLog($sermon);

        Storage::disk('public')->put('sermons/permission-test/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willThrowException(new \Exception('Permission denied'));

        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation job encountered an error',
            \Mockery::on(function (array $context): bool {
                return str_contains($context['error'], 'Permission denied');
            })
        );

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
    }

    #[Test]
    public function thumbnail_job_failure_is_logged_appropriately(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/failure-test/video.mp4',
        ]);
        $job = new GenerateThumbnail($this->createVideoProcessingLog($sermon));

        Log::shouldReceive('warning')->once()->with(
            'GenerateThumbnail job failed permanently',
            \Mockery::on(function (array $context) use ($sermon): bool {
                return $context['sermon_id'] === $sermon->id &&
                       $context['video_path'] === 'sermons/failure-test/video.mp4' &&
                       $context['error'] === 'Job failed permanently' &&
                       isset($context['attempts']);
            })
        );

        $job->failed(new \Exception('Job failed permanently'));
    }

    #[Test]
    public function thumbnail_generation_recovers_from_temporary_failures(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Recovery Test Sermon',
            'video_file_path' => 'sermons/recovery-test/video.mp4',
        ]);
        $log = $this->createVideoProcessingLog($sermon);

        Storage::disk('public')->put('sermons/recovery-test/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->exactly(2))
            ->method('generateThumbnail')
            ->willReturnOnConsecutiveCalls(
                ThumbnailResult::skipped('Temporary failure'),
                ThumbnailResult::success('sermons/thumbnails/recovered.jpg', ['width' => 1280])
            );

        $job1 = new GenerateThumbnail($log);
        $job1->handle($mockService);

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);

        $job2 = new GenerateThumbnail($log->fresh() ?? $log);
        $job2->handle($mockService);

        $sermon->refresh();
        $this->assertEquals('sermons/thumbnails/recovered.jpg', $sermon->thumbnail_file_path);
        $this->assertNotNull($sermon->thumbnail_generated_at);
    }

    #[Test]
    public function thumbnail_generation_handles_database_connection_errors(): void
    {
        $log = new MediaProcessingLog([
            'processing_type' => \App\Enums\MediaType::Video,
            'sermon_id' => 999,
            'video_file_path' => 'sermons/missing-sermon/video.mp4',
        ]);

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Sermon not found for thumbnail generation',
            ['sermon_id' => 999]
        );

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);
    }

    private function createVideoProcessingLog(Sermon $sermon): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->video()->create([
            'sermon_id' => $sermon->getKey(),
            'video_file_path' => $sermon->video_file_path,
        ]);
    }
}
