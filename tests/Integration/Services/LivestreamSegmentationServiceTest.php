<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\LivestreamProcessingResult;
use App\Data\ProcessingResult;
use App\Data\StandardProcessingResponse;
use App\Enums\MediaType;
use App\Jobs\CleanupTemporaryFiles;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\ProcessingInitiator;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Processing\ProcessingRunFailureHandler;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Sermon\LivestreamSegmentationService;
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
        // ProcessingPhaseRegistry resolves its livestream retry offsets from
        // the builder, which this suite replaces in the container — answer
        // with the real chain's classes so retry plans stay realistic.
        $this->pipelineBuilder->shouldReceive('livestreamChainJobClasses')
            ->andReturn((new ProcessingPipelineBuilder)->livestreamChainJobClasses())
            ->byDefault();
        $this->processingInitiator = Mockery::mock(ProcessingInitiator::class);
        $this->app->instance(VideoStorageService::class, $this->storageService);
        $this->app->instance(ProcessingPipelineBuilder::class, $this->pipelineBuilder);
        $this->app->forgetInstance(ProcessingRunFailureHandler::class);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        $this->service = new LivestreamSegmentationService(
            $this->storageService,
            $this->segmentationService,
            $this->processingInitiator,
            app(ProcessingRunOrchestrator::class),
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

        $dummyLog = MediaProcessingLog::factory()->livestream()->pending()->make();
        $this->pipelineBuilder->shouldReceive('buildLivestreamParallelJobs')
            ->andReturn([new CleanupTemporaryFiles($dummyLog)]);
        $this->pipelineBuilder->shouldReceive('buildLivestreamChainJobs')
            ->andReturn([new CleanupTemporaryFiles($dummyLog)]);
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
            ->withArgs(function (...$args): bool {
                [$file, $type, $clientFileDate, $data] = $args;

                return $file instanceof UploadedFile
                    && $type === MediaType::Livestream
                    && $clientFileDate === null
                    && is_array($data)
                    && isset($data['source_file_path'])
                    && isset($data['file_size'])
                    && isset($data['duration'])
                    && isset($data['processing_metadata']);
            })
            ->andReturnUsing(function () {
                return MediaProcessingLog::factory()->livestream()->pending()->create();
            });

        $dummyLog = MediaProcessingLog::factory()->livestream()->pending()->make();
        $this->pipelineBuilder->shouldReceive('buildLivestreamParallelJobs')
            ->andReturn([new CleanupTemporaryFiles($dummyLog)]);
        $this->pipelineBuilder->shouldReceive('buildLivestreamChainJobs')
            ->andReturn([new CleanupTemporaryFiles($dummyLog)]);

        $result = $this->service->startProcessing($file);

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

        $dummyLog = MediaProcessingLog::factory()->livestream()->pending()->make();
        $this->pipelineBuilder->shouldReceive('buildLivestreamParallelJobs')
            ->andReturn([new CleanupTemporaryFiles($dummyLog)]);
        $this->pipelineBuilder->shouldReceive('buildLivestreamChainJobs')
            ->andReturn([new CleanupTemporaryFiles($dummyLog)]);

        $result = $this->service->retryProcessing('retry-test-123');

        $this->assertInstanceOf(LivestreamProcessingResult::class, $result);
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

    // ---- getProcessingStatus (formerly LivestreamStatusService) ----

    #[Test]
    public function it_returns_processing_status_for_existing_record(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $response = $this->service->getProcessingStatus($log->processing_id);

        $this->assertInstanceOf(StandardProcessingResponse::class, $response);
        $this->assertEquals($log->processing_id, $response->processingId);
    }

    #[Test]
    public function it_throws_exception_for_nonexistent_processing_id(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found');

        $this->service->getProcessingStatus('nonexistent-processing-id');
    }

    #[Test]
    public function it_returns_processing_result_for_existing_record(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'original_filename' => 'livestream-2026-01-15.mp4',
            'file_size' => 1073741824,
            'processing_metadata' => ['file_format' => 'mp4'],
        ]);

        $result = $this->service->getProcessingResult($log->processing_id);

        $this->assertEquals($log->processing_id, $result->processingId);
        $this->assertEquals('livestream-2026-01-15.mp4', $result->originalFilename);
    }

    #[Test]
    public function it_throws_exception_when_getting_result_for_nonexistent_record(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Processing record not found');

        $this->service->getProcessingResult('nonexistent-processing-id');
    }

    #[Test]
    public function it_returns_processing_summary_with_correct_counts(): void
    {
        MediaProcessingLog::factory()->livestream()->pending()->count(2)->create();
        MediaProcessingLog::factory()->livestream()->processing()->count(3)->create();
        MediaProcessingLog::factory()->livestream()->completed()->count(4)->create();
        MediaProcessingLog::factory()->livestream()->failed()->count(1)->create();

        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(10, $summary['total_processing_requests']);
        $this->assertEquals(2, $summary['pending']);
        $this->assertEquals(3, $summary['processing']);
        $this->assertEquals(4, $summary['completed']);
        $this->assertEquals(1, $summary['failed']);
    }

    #[Test]
    public function it_calculates_success_rate_correctly(): void
    {
        MediaProcessingLog::factory()->livestream()->completed()->count(8)->create();
        MediaProcessingLog::factory()->livestream()->failed()->count(2)->create();

        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(80.0, $summary['success_rate']);
    }

    #[Test]
    public function it_returns_zero_success_rate_when_no_records(): void
    {
        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(0, $summary['success_rate']);
        $this->assertEquals(0, $summary['total_processing_requests']);
    }

    #[Test]
    public function processing_summary_excludes_non_livestream_logs(): void
    {
        MediaProcessingLog::factory()->audio()->completed()->count(5)->create();
        MediaProcessingLog::factory()->livestream()->completed()->count(2)->create();

        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(2, $summary['total_processing_requests']);
        $this->assertEquals(2, $summary['completed']);
    }

    #[Test]
    public function it_returns_processing_result_with_error_message_when_failed(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'error_message' => 'RMS generation failed',
            'file_size' => 1073741824,
            'processing_metadata' => ['file_format' => 'mp4'],
        ]);

        $result = $this->service->getProcessingResult($log->processing_id);

        $this->assertEquals('failed', $result->status);
        $this->assertEquals('RMS generation failed', $result->errorMessage);
    }

    #[Test]
    public function processing_summary_structure_has_required_keys(): void
    {
        $summary = $this->service->getProcessingSummary();

        $this->assertArrayHasKey('total_processing_requests', $summary);
        $this->assertArrayHasKey('pending', $summary);
        $this->assertArrayHasKey('processing', $summary);
        $this->assertArrayHasKey('completed', $summary);
        $this->assertArrayHasKey('failed', $summary);
        $this->assertArrayHasKey('success_rate', $summary);
    }
}
