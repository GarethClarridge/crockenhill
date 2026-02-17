<?php

namespace Tests\Unit\Services;

use App\Models\MediaProcessingLog;
use App\Services\LivestreamSegmentationService;
use App\Services\ProcessingInitiator;
use App\Services\ProcessingPipelineBuilder;
use App\Services\ProcessingResult;
use App\Services\VideoSegmentationService;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamSegmentationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LivestreamSegmentationService $service;

    private VideoStorageService|Mockery\MockInterface $storageService;

    private VideoSegmentationService|Mockery\MockInterface $segmentationService;

    private ProcessingPipelineBuilder|Mockery\MockInterface $pipelineBuilder;

    private ProcessingInitiator|Mockery\MockInterface $processingInitiator;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Mail::fake();

        Config::set('media-processing.queue.name', 'default');
        Config::set('media-processing.email.admin_email', 'admin@test.com');

        $this->storageService = Mockery::mock(VideoStorageService::class);
        $this->segmentationService = Mockery::mock(VideoSegmentationService::class);
        $this->pipelineBuilder = Mockery::mock(ProcessingPipelineBuilder::class);
        $this->processingInitiator = Mockery::mock(ProcessingInitiator::class);

        $this->service = new LivestreamSegmentationService(
            $this->storageService,
            $this->segmentationService,
            $this->pipelineBuilder,
            $this->processingInitiator,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockUploadResult(string $filename = 'video.mp4'): array
    {
        return [
            'temp_path' => 'livestream/temp/test.mp4',
            'full_path' => '/tmp/test.mp4',
            'original_filename' => $filename,
            'file_size' => 50000000,
            'mime_type' => 'video/mp4',
        ];
    }

    private function mockVideoMetadata(): array
    {
        return [
            'duration' => 3600.0,
            'format_name' => 'mp4',
            'size' => 50000000,
            'bit_rate' => 5000000,
        ];
    }

    private function setupLivestreamMocks(string $filename = 'video.mp4'): void
    {
        $this->storageService->shouldReceive('validateStorageSpace')->andReturn(true);

        $this->storageService->shouldReceive('storeUploadedVideo')
            ->andReturn($this->mockUploadResult($filename));
        $this->segmentationService->shouldReceive('validateVideoFile')->andReturn(true);
        $this->segmentationService->shouldReceive('getVideoMetadata')
            ->andReturn($this->mockVideoMetadata());

        // ProcessingInitiator handles metadata extraction and log creation
        $this->processingInitiator->shouldReceive('initiateProcessing')
            ->andReturnUsing(function () {
                return MediaProcessingLog::factory()->livestream()->pending()->create();
            });

        $this->pipelineBuilder->shouldReceive('buildLivestreamPipeline')
            ->andReturn([new \App\Jobs\CleanupTemporaryFiles(
                MediaProcessingLog::factory()->livestream()->pending()->make()
            )]);
    }

    // ---- Instantiation ----

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(LivestreamSegmentationService::class, $this->service);
    }

    // ---- startProcessing (livestream path) ----

    #[Test]
    public function it_starts_livestream_processing_successfully(): void
    {
        $file = UploadedFile::fake()->create('2024-01-14_morning.mp4', 50000, 'video/mp4');
        $this->setupLivestreamMocks('2024-01-14_morning.mp4');

        $result = $this->service->startProcessing($file);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_type' => 'livestream',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_throws_when_insufficient_storage_space(): void
    {
        $file = UploadedFile::fake()->create('video.mp4', 50000, 'video/mp4');

        $this->storageService->shouldReceive('validateStorageSpace')->andReturn(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient storage space');

        $this->service->startProcessing($file);
    }

    #[Test]
    public function it_throws_when_video_file_is_invalid(): void
    {
        $file = UploadedFile::fake()->create('video.mp4', 50000, 'video/mp4');

        $this->storageService->shouldReceive('validateStorageSpace')->andReturn(true);
        $this->storageService->shouldReceive('storeUploadedVideo')
            ->andReturn($this->mockUploadResult());
        $this->segmentationService->shouldReceive('validateVideoFile')->andReturn(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid video file format');

        $this->service->startProcessing($file);
    }

    #[Test]
    public function it_delegates_metadata_extraction_to_processing_initiator(): void
    {
        $file = UploadedFile::fake()->create('2024-01-14_morning.mp4', 50000, 'video/mp4');

        // Set up storage/segmentation mocks
        $this->storageService->shouldReceive('validateStorageSpace')->andReturn(true);
        $this->storageService->shouldReceive('storeUploadedVideo')
            ->andReturn($this->mockUploadResult('2024-01-14_morning.mp4'));
        $this->segmentationService->shouldReceive('validateVideoFile')->andReturn(true);
        $this->segmentationService->shouldReceive('getVideoMetadata')
            ->andReturn($this->mockVideoMetadata());

        // Expect ProcessingInitiator to be called with livestream type and additional data
        $this->processingInitiator->shouldReceive('initiateProcessing')
            ->once()
            ->with(
                Mockery::type(UploadedFile::class),
                'livestream',
                null,
                Mockery::on(function (array $data) {
                    return isset($data['source_file_path'])
                        && isset($data['file_size'])
                        && isset($data['duration'])
                        && isset($data['processing_metadata']);
                })
            )
            ->andReturnUsing(function () {
                return MediaProcessingLog::factory()->livestream()->pending()->create();
            });

        $this->pipelineBuilder->shouldReceive('buildLivestreamPipeline')
            ->andReturn([new \App\Jobs\CleanupTemporaryFiles(
                MediaProcessingLog::factory()->livestream()->pending()->make()
            )]);

        $result = $this->service->startProcessing($file);

        $this->assertTrue($result->success);
    }

    // ---- processWithSegmentation (delegate method) ----

    #[Test]
    public function it_delegates_process_with_segmentation_to_start_processing(): void
    {
        $file = UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4');
        $this->setupLivestreamMocks('livestream.mp4');

        $result = $this->service->processWithSegmentation($file);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        $this->assertTrue($result->success);
    }

    // ---- retryProcessing ----

    #[Test]
    public function it_retries_failed_processing(): void
    {
        MediaProcessingLog::factory()->livestream()->failed()->create([
            'processing_id' => 'retry-test-123',
            'file_size' => 50000000,
            'original_filename' => 'livestream.mp4',
            'processing_metadata' => ['file_format' => 'mp4'],
        ]);

        $this->pipelineBuilder->shouldReceive('buildLivestreamPipeline')
            ->andReturn([new \App\Jobs\CleanupTemporaryFiles(
                MediaProcessingLog::factory()->livestream()->pending()->make()
            )]);

        $result = $this->service->retryProcessing('retry-test-123');

        $this->assertInstanceOf(\App\Data\LivestreamProcessingResult::class, $result);
        $this->assertEquals('pending', $result->status);
    }

    #[Test]
    public function it_throws_when_retrying_nonexistent_processing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found');

        $this->service->retryProcessing('nonexistent-id');
    }

    #[Test]
    public function it_throws_when_retrying_non_failed_processing(): void
    {
        MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => 'pending-test-123',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only failed or cancelled processing can be retried');

        $this->service->retryProcessing('pending-test-123');
    }

    // ---- cancelProcessing ----

    #[Test]
    public function it_cancels_pending_processing(): void
    {
        MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => 'cancel-test-123',
            'source_file_path' => 'livestream/temp/video.mp4',
        ]);

        $this->storageService->shouldReceive('cleanupTemporaryFiles')
            ->once()
            ->with(Mockery::type('array'));

        $result = $this->service->cancelProcessing('cancel-test-123');

        $this->assertTrue($result);
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => 'cancel-test-123',
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_throws_when_cancelling_nonexistent_processing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found');

        $this->service->cancelProcessing('nonexistent-id');
    }

    #[Test]
    public function it_throws_when_cancelling_completed_processing(): void
    {
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'completed-test-123',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot cancel completed processing');

        $this->service->cancelProcessing('completed-test-123');
    }

    #[Test]
    public function it_cleans_up_temp_files_from_metadata_on_cancel(): void
    {
        MediaProcessingLog::factory()->livestream()->processing()->create([
            'processing_id' => 'cancel-cleanup-123',
            'source_file_path' => 'livestream/temp/video.mp4',
            'processing_metadata' => [
                'extracted_segment_path' => 'temp/segment.mp4',
                'extracted_audio_path' => 'temp/audio.mp3',
                'temp_video_path' => 'temp/video.mp4',
            ],
        ]);

        $this->storageService->shouldReceive('cleanupTemporaryFiles')
            ->once()
            ->with(Mockery::on(function (array $files) {
                return count($files) === 4; // source + 3 metadata paths
            }));

        $result = $this->service->cancelProcessing('cancel-cleanup-123');

        $this->assertTrue($result);
    }

    // ---- updateProcessingStatus ----

    #[Test]
    public function it_updates_processing_status(): void
    {
        MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => 'status-update-123',
        ]);

        $this->service->updateProcessingStatus('status-update-123', 'processing');

        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => 'status-update-123',
            'status' => 'processing',
        ]);
    }

    #[Test]
    public function it_handles_updating_status_for_nonexistent_log(): void
    {
        // Should not throw
        $this->service->updateProcessingStatus('nonexistent-id', 'processing');
        $this->assertTrue(true);
    }
}
