<?php

namespace Tests\Unit\Services;

use App\Data\LivestreamProcessingResult;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\LivestreamSegmentationService;
use App\Services\ProcessingInitiator;
use App\Services\ProcessingLogService;
use App\Services\ProcessingPipelineBuilder;
use App\Services\ProcessingResult;
use App\Services\SermonProcessingService;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnifiedMediaProcessorTest extends TestCase
{
    use RefreshDatabase;

    private UnifiedMediaProcessor $processor;

    private LivestreamSegmentationService $livestreamService;

    private SermonProcessingService $sermonService;

    private ProcessingPipelineBuilder $pipelineBuilder;

    private ProcessingLogService $processingLogService;

    private ProcessingInitiator $processingInitiator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->livestreamService = $this->createMock(LivestreamSegmentationService::class);
        $this->sermonService = $this->createMock(SermonProcessingService::class);
        $this->pipelineBuilder = $this->createMock(ProcessingPipelineBuilder::class);
        $this->processingLogService = $this->createMock(ProcessingLogService::class);
        $this->processingInitiator = $this->createMock(ProcessingInitiator::class);

        $this->processor = new UnifiedMediaProcessor(
            $this->livestreamService,
            $this->sermonService,
            $this->pipelineBuilder,
            $this->processingLogService,
            $this->processingInitiator
        );
    }

    // --- process() routing tests ---

    #[Test]
    public function it_routes_audio_to_sermon_processing_service(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);
        $expectedResult = ProcessingResult::success(
            processingId: 'audio-123',
            message: 'Audio processing started'
        );

        $this->sermonService
            ->method('processSermon')
            ->with($file, null)
            ->willReturn($expectedResult);

        $result = $this->processor->process('audio', $file);

        $this->assertTrue($result->success);
        $this->assertEquals('audio-123', $result->processingId);
    }

    #[Test]
    public function it_routes_audio_with_client_file_date(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);
        $clientFileDate = '2026-02-01';
        $expectedResult = ProcessingResult::success(
            processingId: 'audio-456',
            message: 'Audio processing started'
        );

        $this->sermonService
            ->method('processSermon')
            ->with($file, $clientFileDate)
            ->willReturn($expectedResult);

        $result = $this->processor->process('audio', $file, $clientFileDate);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_routes_livestream_to_livestream_segmentation_service(): void
    {
        $file = UploadedFile::fake()->create('livestream.mp4', 5120);
        $expectedResult = ProcessingResult::success(
            processingId: 'livestream-123',
            message: 'Livestream processing started'
        );

        $this->livestreamService
            ->method('processWithSegmentation')
            ->with($file, null)
            ->willReturn($expectedResult);

        $result = $this->processor->process('livestream', $file);

        $this->assertTrue($result->success);
        $this->assertEquals('livestream-123', $result->processingId);
    }

    #[Test]
    public function it_returns_failure_for_unsupported_media_type(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 512);

        $result = $this->processor->process('document', $file);

        $this->assertFalse($result->success);
        $this->assertEquals('UNSUPPORTED_TYPE', $result->errorCode);
        $this->assertStringContainsString('Unsupported media type: document', $result->message);
    }

    // --- getStatus() tests ---

    #[Test]
    public function it_returns_status_for_existing_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'current_step' => 'transcribing_audio',
        ]);

        $response = $this->processor->getStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertEquals($log->processing_id, $response->processingId);
        $this->assertEquals('processing', $response->status);
    }

    #[Test]
    public function it_returns_not_found_for_nonexistent_processing_id(): void
    {
        $response = $this->processor->getStatus('nonexistent-id');

        $this->assertFalse($response->found);
    }

    #[Test]
    public function it_returns_completed_status_with_sermon_data(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $response = $this->processor->getStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertEquals('completed', $response->status);
        $this->assertNotNull($response->sermonId);
    }

    #[Test]
    public function it_returns_failed_status_with_error_message(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'error_message' => 'Transcription API timeout',
        ]);

        $response = $this->processor->getStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertEquals('failed', $response->status);
        $this->assertEquals('Transcription API timeout', $response->errorMessage);
    }

    // --- getStatusWithLogs() tests ---

    #[Test]
    public function it_returns_status_without_logs_when_not_requested(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $response = $this->processor->getStatusWithLogs($log->processing_id, false);

        $this->assertTrue($response->found);
        $this->assertNull($response->recentLogs);
        $this->assertNull($response->performanceMetrics);
    }

    #[Test]
    public function it_returns_not_found_when_requesting_status_with_logs_for_nonexistent_id(): void
    {
        $response = $this->processor->getStatusWithLogs('nonexistent-id', true);

        $this->assertFalse($response->found);
    }

    // --- cancel() tests ---

    #[Test]
    public function it_cancels_audio_processing_via_sermon_service(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $this->sermonService
            ->method('cancelProcessing')
            ->with($log->processing_id)
            ->willReturn(true);

        $result = $this->processor->cancel($log->processing_id);

        $this->assertTrue($result['success']);
        $this->assertEquals('Processing cancelled successfully', $result['message']);
    }

    #[Test]
    public function it_cancels_video_processing_via_sermon_service(): void
    {
        $log = MediaProcessingLog::factory()->video()->processing()->create();

        $this->sermonService
            ->method('cancelProcessing')
            ->with($log->processing_id)
            ->willReturn(true);

        $result = $this->processor->cancel($log->processing_id);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function it_cancels_livestream_processing_via_livestream_service(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $this->livestreamService
            ->method('cancelProcessing')
            ->with($log->processing_id)
            ->willReturn(true);

        $result = $this->processor->cancel($log->processing_id);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function it_returns_failure_when_cancel_processing_id_not_found(): void
    {
        $result = $this->processor->cancel('nonexistent-id');

        $this->assertFalse($result['success']);
        $this->assertEquals('Processing ID not found', $result['message']);
    }

    #[Test]
    public function it_returns_failure_when_cancel_service_returns_false(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $this->sermonService
            ->method('cancelProcessing')
            ->willReturn(false);

        $result = $this->processor->cancel($log->processing_id);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to cancel processing', $result['message']);
    }

    // --- retry() tests ---

    #[Test]
    public function it_retries_audio_processing_via_sermon_service(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();
        $expectedResult = ProcessingResult::success(
            processingId: $log->processing_id,
            message: 'Retry initiated'
        );

        $this->sermonService
            ->method('retryProcessing')
            ->with($log->processing_id)
            ->willReturn($expectedResult);

        $result = $this->processor->retry($log->processing_id);

        $this->assertTrue($result->success);
        $this->assertEquals($log->processing_id, $result->processingId);
    }

    #[Test]
    public function it_retries_video_processing_via_sermon_service(): void
    {
        $log = MediaProcessingLog::factory()->video()->failed()->create();
        $expectedResult = ProcessingResult::success(
            processingId: $log->processing_id,
            message: 'Video retry initiated'
        );

        $this->sermonService
            ->method('retryProcessing')
            ->with($log->processing_id)
            ->willReturn($expectedResult);

        $result = $this->processor->retry($log->processing_id);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_retries_livestream_processing_via_livestream_service(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create();
        $livestreamResult = new LivestreamProcessingResult(
            processingId: $log->processing_id,
            status: 'processing',
            originalFilename: 'livestream.mp4',
            fileSize: 1073741824,
            fileFormat: 'mp4',
            errorMessage: null
        );

        $this->livestreamService
            ->method('retryProcessing')
            ->with($log->processing_id)
            ->willReturn($livestreamResult);

        $result = $this->processor->retry($log->processing_id);

        $this->assertTrue($result->success);
        $this->assertEquals($log->processing_id, $result->processingId);
        $this->assertStringContainsString('Livestream processing retry initiated', $result->message);
    }

    #[Test]
    public function it_converts_failed_livestream_retry_to_failure_result(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->failed()->create();
        $livestreamResult = new LivestreamProcessingResult(
            processingId: $log->processing_id,
            status: 'failed',
            originalFilename: 'livestream.mp4',
            fileSize: 1073741824,
            fileFormat: 'mp4',
            errorMessage: 'Retry failed: storage unavailable'
        );

        $this->livestreamService
            ->method('retryProcessing')
            ->with($log->processing_id)
            ->willReturn($livestreamResult);

        $result = $this->processor->retry($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('RETRY_FAILED', $result->errorCode);
        $this->assertStringContainsString('storage unavailable', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_retry_processing_id_not_found(): void
    {
        $result = $this->processor->retry('nonexistent-id');

        $this->assertFalse($result->success);
        $this->assertEquals('NOT_FOUND', $result->errorCode);
    }

    // --- canHandle() tests ---

    #[Test]
    public function it_returns_true_when_processing_id_exists(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $this->assertTrue($this->processor->canHandle($log->processing_id));
    }

    #[Test]
    public function it_returns_false_when_processing_id_does_not_exist(): void
    {
        $this->assertFalse($this->processor->canHandle('nonexistent-id'));
    }

    // --- processDirectVideo() (tested via process('video', ...)) ---

    #[Test]
    public function it_processes_direct_video_via_processing_initiator(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->create('2026-01-15_morning.mp4', 2048);

        $processingLog = MediaProcessingLog::factory()->video()->pending()->create([
            'original_filename' => '2026-01-15_morning.mp4',
            'current_step' => 'video_processing_initiated',
        ]);

        $this->processingInitiator
            ->method('initiateProcessing')
            ->willReturn($processingLog);

        $this->pipelineBuilder
            ->method('buildDirectVideoPipeline')
            ->willReturn([new \App\Jobs\TestJob]);

        $result = $this->processor->process('video', $file);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Video processing initiated', $result->message);
    }

    #[Test]
    public function it_creates_processing_log_for_direct_video(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->create('sermon-video.mp4', 2048);

        $processingLog = MediaProcessingLog::factory()->video()->pending()->create([
            'original_filename' => 'sermon-video.mp4',
            'current_step' => 'video_processing_initiated',
        ]);

        $this->processingInitiator
            ->method('initiateProcessing')
            ->willReturn($processingLog);

        $this->pipelineBuilder
            ->method('buildDirectVideoPipeline')
            ->willReturn([new \App\Jobs\TestJob]);

        $this->processor->process('video', $file);

        $this->assertDatabaseHas('media_processing_logs', [
            'processing_type' => 'video',
            'original_filename' => 'sermon-video.mp4',
            'status' => ProcessingStatus::PENDING->value,
            'current_step' => 'video_processing_initiated',
        ]);
    }

    #[Test]
    public function it_returns_failure_when_direct_video_processing_throws_exception(): void
    {
        $file = UploadedFile::fake()->create('bad-video.mp4', 2048);

        $this->processingInitiator
            ->method('initiateProcessing')
            ->willThrowException(new \RuntimeException('Cannot read video metadata'));

        $result = $this->processor->process('video', $file);

        $this->assertFalse($result->success);
        $this->assertEquals('VIDEO_PROCESSING_FAILED', $result->errorCode);
        $this->assertStringContainsString('Cannot read video metadata', $result->message);
    }

    #[Test]
    public function it_stores_extracted_metadata_in_processing_log(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->create('2026-02-10.mp4', 2048);

        $processingLog = MediaProcessingLog::factory()->video()->pending()->create([
            'original_filename' => '2026-02-10.mp4',
            'processing_metadata' => [
                'extracted_date' => '2026-02-10',
                'extracted_service' => 'evening',
                'date_extraction_method' => 'video_metadata_or_filename',
                'service_extraction_method' => 'datetime_timestamp',
            ],
        ]);

        $this->processingInitiator
            ->method('initiateProcessing')
            ->willReturn($processingLog);

        $this->pipelineBuilder
            ->method('buildDirectVideoPipeline')
            ->willReturn([new \App\Jobs\TestJob]);

        $this->processor->process('video', $file);

        $log = MediaProcessingLog::where('original_filename', '2026-02-10.mp4')->first();

        $this->assertNotNull($log);
        $this->assertEquals('2026-02-10', $log->processing_metadata['extracted_date']);
        $this->assertEquals('evening', $log->processing_metadata['extracted_service']);
    }
}
