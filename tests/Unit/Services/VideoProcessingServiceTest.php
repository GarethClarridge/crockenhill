<?php

namespace Tests\Unit\Services;

use App\Data\LivestreamProcessingResult;
use App\Data\StandardProcessingResponse;
use App\Services\LivestreamSegmentationService;
use App\Services\LivestreamStatusService;
use App\Services\ProcessingResult;
use App\Services\VideoProcessingService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoProcessingServiceTest extends TestCase
{
    private VideoProcessingService $service;

    private LivestreamSegmentationService $segmentationService;

    private LivestreamStatusService $statusService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segmentationService = $this->createMock(LivestreamSegmentationService::class);
        $this->statusService = $this->createMock(LivestreamStatusService::class);

        $this->service = new VideoProcessingService(
            $this->segmentationService,
            $this->statusService
        );
    }

    #[Test]
    public function it_delegates_process_livestream_to_segmentation_service(): void
    {
        $file = UploadedFile::fake()->create('livestream.mp4', 5120);
        $expectedResult = ProcessingResult::success(
            processingId: 'livestream-123',
            message: 'Livestream processing started'
        );

        $this->segmentationService
            ->method('processWithSegmentation')
            ->with($file)
            ->willReturn($expectedResult);

        $result = $this->service->processLivestream($file);

        $this->assertTrue($result->success);
        $this->assertEquals('livestream-123', $result->processingId);
    }

    #[Test]
    public function it_delegates_process_directly_to_segmentation_service(): void
    {
        $file = UploadedFile::fake()->create('sermon-video.mp4', 2048);
        $expectedResult = ProcessingResult::success(
            processingId: 'direct-123',
            message: 'Direct video processing started'
        );

        $this->segmentationService
            ->method('processDirectly')
            ->with($file)
            ->willReturn($expectedResult);

        $result = $this->service->processDirectly($file);

        $this->assertTrue($result->success);
        $this->assertEquals('direct-123', $result->processingId);
    }

    #[Test]
    public function it_delegates_process_with_segmentation(): void
    {
        $file = UploadedFile::fake()->create('livestream.mp4', 5120);
        $clientFileDate = '2026-01-15';
        $expectedResult = ProcessingResult::success(
            processingId: 'seg-123',
            message: 'Segmentation processing started'
        );

        $this->segmentationService
            ->method('processWithSegmentation')
            ->with($file, $clientFileDate)
            ->willReturn($expectedResult);

        $result = $this->service->processWithSegmentation($file, $clientFileDate);

        $this->assertTrue($result->success);
        $this->assertEquals('seg-123', $result->processingId);
    }

    #[Test]
    public function it_passes_null_client_file_date_by_default(): void
    {
        $file = UploadedFile::fake()->create('livestream.mp4', 5120);
        $expectedResult = ProcessingResult::success(
            processingId: 'seg-456',
            message: 'Segmentation processing started'
        );

        $this->segmentationService
            ->method('processWithSegmentation')
            ->with($file, null)
            ->willReturn($expectedResult);

        $result = $this->service->processWithSegmentation($file);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_delegates_get_processing_status_to_status_service(): void
    {
        $processingId = 'status-123';
        $expectedResponse = StandardProcessingResponse::found(
            processingId: $processingId,
            status: 'processing',
            currentStep: 'generating_rms',
            progressPercentage: 20
        );

        $this->statusService
            ->method('getProcessingStatus')
            ->with($processingId)
            ->willReturn($expectedResponse);

        $response = $this->service->getProcessingStatus($processingId);

        $this->assertTrue($response->found);
        $this->assertEquals($processingId, $response->processingId);
        $this->assertEquals('processing', $response->status);
        $this->assertEquals(20, $response->progressPercentage);
    }

    #[Test]
    public function it_delegates_get_processing_result_to_status_service(): void
    {
        $processingId = 'result-123';
        $expectedResult = new LivestreamProcessingResult(
            processingId: $processingId,
            status: 'completed',
            originalFilename: 'livestream-2026-01-15.mp4',
            fileSize: 1073741824,
            fileFormat: 'mp4',
            duration: 5400.5,
            sermonId: 42
        );

        $this->statusService
            ->method('getProcessingResult')
            ->with($processingId)
            ->willReturn($expectedResult);

        $result = $this->service->getProcessingResult($processingId);

        $this->assertEquals($processingId, $result->processingId);
        $this->assertEquals('completed', $result->status);
        $this->assertEquals(42, $result->sermonId);
        $this->assertEquals(5400.5, $result->duration);
    }

    #[Test]
    public function it_delegates_retry_processing_to_segmentation_service(): void
    {
        $processingId = 'retry-123';
        $expectedResult = new LivestreamProcessingResult(
            processingId: $processingId,
            status: 'processing',
            originalFilename: 'livestream.mp4',
            fileSize: 1073741824,
            fileFormat: 'mp4'
        );

        $this->segmentationService
            ->method('retryProcessing')
            ->with($processingId)
            ->willReturn($expectedResult);

        $result = $this->service->retryProcessing($processingId);

        $this->assertEquals($processingId, $result->processingId);
        $this->assertEquals('processing', $result->status);
    }

    #[Test]
    public function it_delegates_cancel_processing_to_segmentation_service(): void
    {
        $processingId = 'cancel-123';

        $this->segmentationService
            ->method('cancelProcessing')
            ->with($processingId)
            ->willReturn(true);

        $result = $this->service->cancelProcessing($processingId);

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_cancel_fails(): void
    {
        $processingId = 'cancel-fail-123';

        $this->segmentationService
            ->method('cancelProcessing')
            ->with($processingId)
            ->willReturn(false);

        $result = $this->service->cancelProcessing($processingId);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_delegates_update_processing_status_to_segmentation_service(): void
    {
        $processingId = 'update-123';

        $this->segmentationService
            ->expects($this->once())
            ->method('updateProcessingStatus')
            ->with($processingId, 'extracting_sermon');

        $this->service->updateProcessingStatus($processingId, 'extracting_sermon');
    }

    #[Test]
    public function it_delegates_get_processing_summary_to_status_service(): void
    {
        $expectedSummary = [
            'total_processing_requests' => 25,
            'pending' => 2,
            'processing' => 3,
            'completed' => 18,
            'failed' => 2,
            'success_rate' => 90.0,
        ];

        $this->statusService
            ->method('getProcessingSummary')
            ->willReturn($expectedSummary);

        $summary = $this->service->getProcessingSummary();

        $this->assertEquals(25, $summary['total_processing_requests']);
        $this->assertEquals(90.0, $summary['success_rate']);
        $this->assertArrayHasKey('pending', $summary);
        $this->assertArrayHasKey('completed', $summary);
    }
}
