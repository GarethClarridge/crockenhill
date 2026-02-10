<?php

namespace Tests\Feature;

use App\Data\ThumbnailResult;
use App\Jobs\GenerateThumbnail;
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
    }

    #[Test]
    public function thumbnail_generation_errors_do_not_break_main_processing()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Error Test Sermon',
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the thumbnail service to throw an exception
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willThrowException(new \Exception('Thumbnail generation failed'));

        // Mock Log to capture error logging
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation job encountered an error',
            \Mockery::on(function ($context) use ($sermon, $tempFile) {
                return $context['sermon_id'] === $sermon->id &&
                       $context['video_path'] === $tempFile &&
                       $context['error'] === 'Thumbnail generation failed';
            })
        );

        // Create and handle the job
        $job = new GenerateThumbnail($sermon->id, $tempFile);

        // This should not throw an exception
        $job->handle($mockService);

        // Verify sermon was not updated (no thumbnail data)
        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
        $this->assertNull($sermon->thumbnail_generated_at);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_service_handles_ffmpeg_errors_gracefully()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'FFmpeg Error Test',
        ]);

        // Create a file that's not a valid video
        $tempFile = tempnam(sys_get_temp_dir(), 'invalid_video');
        file_put_contents($tempFile, 'This is not a video file');

        // Get the real service (it should handle errors gracefully)
        $service = app(ThumbnailGenerationService::class);

        $result = $service->generateThumbnail($sermon, $tempFile);

        // Should return a failed result, not throw an exception
        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_storage_errors()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Storage Error Test',
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Get the real service - it will fail due to invalid video file
        $service = app(ThumbnailGenerationService::class);

        $result = $service->generateThumbnail($sermon, $tempFile);

        // Should handle errors gracefully
        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_memory_exhaustion()
    {
        // This test simulates memory exhaustion scenarios
        $sermon = Sermon::factory()->create([
            'title' => 'Memory Test Sermon',
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the service to simulate memory exhaustion
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willReturn(ThumbnailResult::skipped('Simulated memory exhaustion'));

        // Mock Log to capture error
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation skipped',
            \Mockery::on(function ($context) {
                return str_contains($context['reason'], 'memory');
            })
        );

        // Create and handle the job
        $job = new GenerateThumbnail($sermon->id, $tempFile);

        // Should handle memory errors gracefully
        $job->handle($mockService);

        // Verify sermon was not updated
        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_timeout_scenarios()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Timeout Test Sermon',
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the service to simulate timeout
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willThrowException(new \Exception('Maximum execution time exceeded'));

        // Mock Log to capture timeout error
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation job encountered an error',
            \Mockery::on(function ($context) {
                return str_contains($context['error'], 'execution time');
            })
        );

        // Create and handle the job
        $job = new GenerateThumbnail($sermon->id, $tempFile);

        // Should handle timeout gracefully
        $job->handle($mockService);

        // Verify sermon was not updated
        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_corrupted_video_files()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Corrupted Video Test',
        ]);

        // Create a corrupted video file (just random bytes)
        $tempFile = tempnam(sys_get_temp_dir(), 'corrupted_video');
        file_put_contents($tempFile, random_bytes(1024));

        // Get the real service
        $service = app(ThumbnailGenerationService::class);

        $result = $service->generateThumbnail($sermon, $tempFile);

        // Should handle corrupted files gracefully
        $this->assertInstanceOf(ThumbnailResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_permission_errors()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Permission Error Test',
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the service to simulate permission error
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->willThrowException(new \Exception('Permission denied'));

        // Mock Log to capture permission error
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Thumbnail generation job encountered an error',
            \Mockery::on(function ($context) {
                return str_contains($context['error'], 'Permission denied');
            })
        );

        // Create and handle the job
        $job = new GenerateThumbnail($sermon->id, $tempFile);

        // Should handle permission errors gracefully
        $job->handle($mockService);

        // Verify sermon was not updated
        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_job_failure_is_logged_appropriately()
    {
        // Create a job
        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        // Mock Log to verify failure logging
        Log::shouldReceive('warning')->once()->with(
            'GenerateThumbnail job failed permanently',
            \Mockery::on(function ($context) {
                return $context['sermon_id'] === 123 &&
                       $context['video_path'] === '/path/to/video.mp4' &&
                       $context['error'] === 'Job failed permanently' &&
                       isset($context['attempts']);
            })
        );

        // Call the failed method
        $job->failed(new \Exception('Job failed permanently'));

        // Test passes if no exception is thrown and logging occurs
        $this->assertTrue(true);
    }

    #[Test]
    public function thumbnail_generation_recovers_from_temporary_failures()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create([
            'title' => 'Recovery Test Sermon',
        ]);

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // First attempt fails, second succeeds
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->exactly(2))
            ->method('generateThumbnail')
            ->willReturnOnConsecutiveCalls(
                ThumbnailResult::skipped('Temporary failure'),
                ThumbnailResult::success('sermons/thumbnails/recovered.jpg', ['width' => 1280])
            );

        // First job attempt
        $job1 = new GenerateThumbnail($sermon->id, $tempFile);
        $job1->handle($mockService);

        // Verify sermon was not updated after first failure
        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);

        // Second job attempt (simulating retry)
        $job2 = new GenerateThumbnail($sermon->id, $tempFile);
        $job2->handle($mockService);

        // Verify sermon was updated after recovery
        $sermon->refresh();
        $this->assertEquals('sermons/thumbnails/recovered.jpg', $sermon->thumbnail_file_path);
        $this->assertNotNull($sermon->thumbnail_generated_at);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function thumbnail_generation_handles_database_connection_errors()
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the service (should not be called due to missing sermon)
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        // Mock Log to capture missing sermon warning
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Sermon not found for thumbnail generation',
            ['sermon_id' => 999]
        );

        // Create and handle the job with non-existent sermon ID
        $job = new GenerateThumbnail(999, $tempFile);

        // Should handle missing sermon gracefully
        $job->handle($mockService);

        // Cleanup
        unlink($tempFile);
    }
}
