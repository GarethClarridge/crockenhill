<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\ThumbnailResult;
use App\Enums\MediaType;
use App\Jobs\GenerateThumbnail;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateThumbnailJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
        ]);
    }

    #[Test]
    public function it_can_instantiate_job_from_processing_log(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $log = $this->createProcessingLog($sermon, 'sermons/1/video.mp4');

        $job = new GenerateThumbnail($log);

        $this->assertInstanceOf(GenerateThumbnail::class, $job);
        $this->assertSame($sermon->id, $job->getSermonId());
        $this->assertSame('sermons/1/video.mp4', $job->getVideoPath());
    }

    #[Test]
    public function it_has_correct_job_configuration(): void
    {
        $job = new GenerateThumbnail(MediaProcessingLog::factory()->video()->processing()->create());

        $this->assertSame(1, $job->tries);
        $this->assertSame(300, $job->timeout);
        $this->assertNull($job->queue);
    }

    #[Test]
    public function it_handles_successful_thumbnail_generation(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);
        $log = $this->createProcessingLog($sermon, 'sermons/1/video.mp4');

        Storage::disk('public')->put('sermons/1/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($sermon)), 'sermons/1/video.mp4', 'public')
            ->willReturn(ThumbnailResult::success(
                'sermons/thumbnails/test.jpg',
                ['width' => 1280, 'height' => 720]
            ));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->never();

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);

        $sermon->refresh();
        $this->assertSame('sermons/thumbnails/test.jpg', $sermon->thumbnail_file_path);
        $this->assertNotNull($sermon->thumbnail_generated_at);
        $this->assertSame(['width' => 1280, 'height' => 720], $sermon->thumbnail_metadata?->toArray());
    }

    #[Test]
    public function it_handles_failed_thumbnail_generation(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);
        $log = $this->createProcessingLog($sermon, 'sermons/1/video.mp4');

        Storage::disk('public')->put('sermons/1/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($sermon)), 'sermons/1/video.mp4', 'public')
            ->willReturn(ThumbnailResult::skipped('Test failure'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
        $this->assertNull($sermon->thumbnail_generated_at);
        $this->assertNull($sermon->thumbnail_metadata);
    }

    #[Test]
    public function it_handles_missing_sermon_gracefully(): void
    {
        // A log with no sermon_id and no video_file_path results in an early return
        // before any thumbnail generation is attempted.
        $log = MediaProcessingLog::factory()->video()->processing()->create([
            'sermon_id' => null,
            'video_file_path' => null,
        ]);

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        Log::shouldReceive('error')->once()->with(
            'Missing sermon ID or video path for thumbnail generation',
            \Mockery::any()
        );

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_handles_missing_video_file_gracefully(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/missing.mp4',
        ]);
        $log = $this->createProcessingLog($sermon, 'sermons/1/missing.mp4');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with(
            'Video file not found for thumbnail generation',
            [
                'sermon_id' => $sermon->id,
                'video_path' => 'sermons/1/missing.mp4',
                'disk' => 'public',
            ]
        );

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_handles_service_exceptions_gracefully(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);
        $log = $this->createProcessingLog($sermon, 'sermons/1/video.mp4');

        Storage::disk('public')->put('sermons/1/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($sermon)), 'sermons/1/video.mp4', 'public')
            ->willThrowException(new \Exception('Service error'));

        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
    }

    #[Test]
    public function it_resolves_livestream_video_path_from_processing_metadata(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'sermon_id' => $sermon->id,
            'video_file_path' => null,
            'processing_metadata' => [
                'final_video_path' => 'sermons/1/final-video.mp4',
            ],
        ]);

        Storage::disk('public')->put('sermons/1/final-video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($sermon)), 'sermons/1/final-video.mp4', 'public')
            ->willReturn(ThumbnailResult::skipped('Not needed for this test'));

        Log::shouldReceive('debug')->atLeast()->once();
        Log::shouldReceive('info')->atLeast()->once();
        Log::shouldReceive('warning')->atLeast()->once();

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_has_correct_job_tags(): void
    {
        $sermon = Sermon::factory()->create();
        $job = new GenerateThumbnail($this->createProcessingLog($sermon, 'sermons/1/video.mp4'));

        $tags = $job->tags();

        $this->assertContains('thumbnail-generation', $tags);
        $this->assertContains('sermon:'.$sermon->id, $tags);
        $this->assertContains('non-critical', $tags);
    }

    #[Test]
    public function it_has_correct_retry_until_time(): void
    {
        $job = new GenerateThumbnail(MediaProcessingLog::factory()->video()->processing()->create());

        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(\DateTime::class, $retryUntil);
        $this->assertEqualsWithDelta(
            now()->addDay()->timestamp,
            $retryUntil->getTimestamp(),
            120
        );
    }

    #[Test]
    public function it_handles_job_failure_gracefully(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);
        $job = new GenerateThumbnail($this->createProcessingLog($sermon, 'sermons/1/video.mp4'));

        Log::shouldReceive('warning')->once()->with(
            'GenerateThumbnail job failed permanently',
            \Mockery::on(function (array $context) use ($sermon): bool {
                return $context['sermon_id'] === $sermon->id &&
                    $context['video_path'] === 'sermons/1/video.mp4' &&
                    $context['error'] === 'Test failure' &&
                    isset($context['attempts']);
            })
        );

        $job->failed(new \Exception('Test failure'));
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        Log::shouldReceive('info')->once()->with('GenerateThumbnail job skipped: processing cancelled', \Mockery::any());

        $job = new GenerateThumbnail($log);
        $job->handle($mockService);
    }

    private function createProcessingLog(
        Sermon $sermon,
        string $videoPath,
        MediaType $type = MediaType::Video
    ): MediaProcessingLog {
        return MediaProcessingLog::factory()->processing()->create([
            'processing_type' => $type,
            'sermon_id' => $sermon->id,
            'video_file_path' => $videoPath,
        ]);
    }
}
