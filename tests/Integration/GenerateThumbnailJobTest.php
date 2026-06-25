<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Data\ThumbnailResult;
use App\Enums\MediaType;
use App\Enums\SermonVideoQualityStatus;
use App\Jobs\GenerateThumbnail;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Thumbnail\ThumbnailGenerationService;
use App\Services\Media\Video\FrameExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        Log::spy();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_can_instantiate_job_from_processing_log(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
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
        $this->assertNull($job->connection);
        $this->assertNull($job->queue);
    }

    #[Test]
    public function it_handles_successful_thumbnail_generation(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
        ]);
        $log = $this->createProcessingLog($sermon, 'sermons/1/video.mp4');

        Storage::disk('public')->put('sermons/1/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('generateThumbnail')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($sermon)), 'sermons/1/video.mp4', 'public')
            ->willReturn(ThumbnailResult::success(
                'sermons/thumbnails/test.jpg',
                [
                    'width' => 1280,
                    'height' => 720,
                    'selected_thumbnail_candidate_id' => 'candidate-3',
                    'thumbnail_candidates' => [
                        [
                            'id' => 'candidate-3',
                            'timestamp' => 420.0,
                            'score' => 0.92,
                            'overlay_path' => 'sermons/thumbnails/test.jpg',
                            'plain_path' => 'sermons/thumbnails/test-plain.jpg',
                        ],
                    ],
                ]
            ));

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        $sermon->refresh();
        $this->assertSame('sermons/thumbnails/test.jpg', $sermon->thumbnail_file_path);
        $this->assertNotNull($sermon->thumbnail_generated_at);
        $this->assertSame('candidate-3', $sermon->thumbnail_metadata?->selectedThumbnailCandidateId);
        $this->assertSame('sermons/thumbnails/test-plain.jpg', $sermon->thumbnail_metadata?->thumbnailCandidates[0]['plain_path']);

        Log::shouldHaveReceived('info')->atLeast()->once();
        Log::shouldNotHaveReceived('warning');
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

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);
        $this->assertNull($sermon->thumbnail_generated_at);
        $this->assertNull($sermon->thumbnail_metadata);

        Log::shouldHaveReceived('info')->atLeast()->once();
        Log::shouldHaveReceived('warning')->atLeast()->once();
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

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        Log::shouldHaveReceived('error')->once()->with(
            'Missing sermon ID or video path for thumbnail generation',
            \Mockery::any()
        );
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

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        Log::shouldHaveReceived('info')->once();
        Log::shouldHaveReceived('warning')->once()->with(
            'Video file not found for thumbnail generation',
            [
                'sermon_id' => $sermon->id,
                'video_path' => 'sermons/1/missing.mp4',
                'disk' => 'public',
            ]
        );
    }

    #[Test]
    public function it_skips_thumbnail_generation_for_rejected_videos(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);
        $log = $this->createProcessingLog($sermon, 'sermons/1/video.mp4');

        Storage::disk('public')->put('sermons/1/video.mp4', 'fake video content');

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);

        Log::shouldHaveReceived('info')->twice();
        Log::shouldNotHaveReceived('warning');
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

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        $sermon->refresh();
        $this->assertNull($sermon->thumbnail_file_path);

        Log::shouldHaveReceived('info')->atLeast()->once();
        Log::shouldHaveReceived('warning')->atLeast()->once();
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

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        Log::shouldHaveReceived('debug')->atLeast()->once();
        Log::shouldHaveReceived('info')->atLeast()->once();
        Log::shouldHaveReceived('warning')->atLeast()->once();
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
        Carbon::setTestNow('2026-05-27 12:00:00');
        $job = new GenerateThumbnail(MediaProcessingLog::factory()->video()->processing()->create());

        $retryUntil = $job->retryUntil();

        $this->assertInstanceOf(\DateTime::class, $retryUntil);
        $this->assertEquals(
            now()->addDay()->timestamp,
            $retryUntil->getTimestamp()
        );
    }

    #[Test]
    public function it_handles_job_failure_gracefully(): void
    {
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);
        $job = new GenerateThumbnail($this->createProcessingLog($sermon, 'sermons/1/video.mp4'));

        $job->failed(new \Exception('Test failure'));

        Log::shouldHaveReceived('warning')->once()->with(
            'GenerateThumbnail job failed permanently',
            \Mockery::on(function (array $context) use ($sermon): bool {
                return $context['sermon_id'] === $sermon->id &&
                    $context['video_path'] === 'sermons/1/video.mp4' &&
                    $context['error'] === 'Test failure' &&
                    isset($context['attempts']);
            })
        );
    }

    #[Test]
    public function it_skips_all_work_when_processing_is_cancelled(): void
    {
        $log = MediaProcessingLog::factory()->video()->cancelled()->create([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->never())->method('generateThumbnail');

        $job = new GenerateThumbnail($log);
        $job->handle($mockService, $this->createStub(FrameExtractionService::class));

        Log::shouldHaveReceived('info')->once()->with('GenerateThumbnail job skipped: processing cancelled', \Mockery::any());
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
