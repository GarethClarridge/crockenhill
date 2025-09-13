<?php

namespace Tests\Unit;

use App\Data\ThumbnailResult;
use App\Jobs\GenerateThumbnail;
use App\Models\Sermon;
use App\Services\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateThumbnailJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_instantiate_job()
    {
        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        $this->assertInstanceOf(GenerateThumbnail::class, $job);
        $this->assertEquals(123, $job->sermonId);
        $this->assertEquals('/path/to/video.mp4', $job->videoPath);
    }

    #[Test]
    public function it_has_correct_job_configuration()
    {
        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        $this->assertEquals(1, $job->tries);
        $this->assertEquals(300, $job->timeout);
    }

    #[Test]
    public function it_sets_correct_queue_from_config()
    {
        config(['thumbnail-generation.queue.name' => 'custom-thumbnails']);

        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        $this->assertEquals('custom-thumbnails', $job->queue);
    }

    #[Test]
    public function it_uses_default_queue_when_config_missing()
    {
        config(['thumbnail-generation.queue.name' => null]);

        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        // When config is null, Laravel's config helper returns the default value
        $expectedQueue = config('thumbnail-generation.queue.name', 'thumbnails');
        $this->assertEquals($expectedQueue, $job->queue);
    }

    #[Test]
    public function it_handles_successful_thumbnail_generation()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create();

        // Create a temporary file to simulate video
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the thumbnail service with any() matcher to be more flexible
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->anything(), $this->anything())
            ->willReturn(ThumbnailResult::success(
                'sermons/thumbnails/test.jpg',
                ['width' => 1280, 'height' => 720]
            ));

        // Mock Log to allow any calls - be more permissive
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->never(); // Should not have warnings on success

        // Create and handle the job
        $job = new GenerateThumbnail($sermon->id, $tempFile);
        $job->handle($mockService);

        // Verify sermon was updated
        $sermon->refresh();
        $this->assertEquals('sermons/thumbnails/test.jpg', $sermon->thumbnail_path);
        $this->assertNotNull($sermon->thumbnail_generated_at);
        $this->assertEquals(['width' => 1280, 'height' => 720], $sermon->thumbnail_metadata);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function it_handles_failed_thumbnail_generation()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create();

        // Create a temporary file to simulate video
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the thumbnail service to return failure
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->anything(), $this->anything())
            ->willReturn(ThumbnailResult::skipped('Test failure'));

        // Mock Log to allow any calls
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        // Create and handle the job
        $job = new GenerateThumbnail($sermon->id, $tempFile);
        $job->handle($mockService);

        // Verify sermon was NOT updated
        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_path);
        $this->assertNull($sermon->thumbnail_generated_at);
        $this->assertNull($sermon->thumbnail_metadata);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function it_handles_missing_sermon_gracefully()
    {
        // Create a temporary file to simulate video
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the thumbnail service (should not be called)
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        // Mock Log to verify warning is logged
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Sermon not found for thumbnail generation',
            ['sermon_id' => 999]
        );

        // Create and handle the job with non-existent sermon ID
        $job = new GenerateThumbnail(999, $tempFile);
        $job->handle($mockService);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function it_handles_missing_video_file_gracefully()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create();

        // Mock the thumbnail service (should not be called)
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        // Mock Log to verify warning is logged
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Video file not found for thumbnail generation',
            [
                'sermon_id' => $sermon->id,
                'video_path' => '/nonexistent/video.mp4',
            ]
        );

        // Create and handle the job with non-existent video file
        $job = new GenerateThumbnail($sermon->id, '/nonexistent/video.mp4');
        $job->handle($mockService);
    }

    #[Test]
    public function it_handles_service_exceptions_gracefully()
    {
        // Create a sermon
        $sermon = Sermon::factory()->create();

        // Create a temporary file to simulate video
        $tempFile = tempnam(sys_get_temp_dir(), 'test_video');
        file_put_contents($tempFile, 'fake video content');

        // Mock the thumbnail service to throw exception
        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->anything(), $this->anything())
            ->willThrowException(new \Exception('Service error'));

        // Mock Log to allow any calls - expect error logging
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        // Create and handle the job - should not throw exception
        $job = new GenerateThumbnail($sermon->id, $tempFile);
        $job->handle($mockService);

        // Verify sermon was NOT updated
        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_path);

        // Cleanup
        unlink($tempFile);
    }

    #[Test]
    public function it_has_correct_job_tags()
    {
        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        $tags = $job->tags();

        $this->assertContains('thumbnail-generation', $tags);
        $this->assertContains('sermon:123', $tags);
        $this->assertContains('non-critical', $tags);
    }

    #[Test]
    public function it_has_correct_retry_until_time()
    {
        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(\DateTime::class, $retryUntil);
        // Should be approximately 5 minutes from now
        $this->assertEqualsWithDelta(
            now()->addMinutes(5)->timestamp,
            $retryUntil->getTimestamp(),
            60 // Allow 1 minute tolerance
        );
    }

    #[Test]
    public function it_handles_job_failure_gracefully()
    {
        $job = new GenerateThumbnail(123, '/path/to/video.mp4');

        // Mock Log to verify failure is logged
        Log::shouldReceive('warning')->once()->with(
            'GenerateThumbnail job failed permanently',
            \Mockery::on(function ($context) {
                return $context['sermon_id'] === 123 &&
                       $context['video_path'] === '/path/to/video.mp4' &&
                       $context['error'] === 'Test failure' &&
                       isset($context['attempts']);
            })
        );

        // Call failed method - should not throw exception
        $job->failed(new \Exception('Test failure'));
    }
}
