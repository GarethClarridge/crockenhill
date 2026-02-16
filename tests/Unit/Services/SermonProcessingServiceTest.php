<?php

namespace Tests\Unit\Services;

use App\Data\StandardProcessingResponse;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\ProcessingResult;
use App\Services\SermonAudioProcessingService;
use App\Services\SermonJobPipelineService;
use App\Services\SermonProcessingLogger;
use App\Services\SermonProcessingService;
use App\Services\SermonStatusManagementService;
use App\Services\SermonValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonProcessingService $service;

    private SermonAudioProcessingService $audioProcessingService;

    private SermonJobPipelineService $jobPipelineService;

    private SermonStatusManagementService $statusManagementService;

    private SermonValidationService $validationService;

    private SermonProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audioProcessingService = $this->createMock(SermonAudioProcessingService::class);
        $this->jobPipelineService = $this->createMock(SermonJobPipelineService::class);
        $this->statusManagementService = $this->createMock(SermonStatusManagementService::class);
        $this->validationService = $this->createMock(SermonValidationService::class);
        $this->logger = $this->createMock(SermonProcessingLogger::class);

        $this->service = new SermonProcessingService(
            $this->audioProcessingService,
            $this->jobPipelineService,
            $this->statusManagementService,
            $this->validationService,
            $this->logger
        );
    }

    #[Test]
    public function it_delegates_process_sermon_to_audio_processing_service(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);
        $expectedResult = ProcessingResult::success(
            processingId: 'test-123',
            message: 'Processing started'
        );

        $this->audioProcessingService
            ->method('processSermon')
            ->with($file, null)
            ->willReturn($expectedResult);

        $result = $this->service->processSermon($file);

        $this->assertTrue($result->success);
        $this->assertEquals('test-123', $result->processingId);
    }

    #[Test]
    public function it_passes_client_file_date_to_audio_processing_service(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);
        $clientFileDate = '2026-01-15';
        $expectedResult = ProcessingResult::success(
            processingId: 'test-456',
            message: 'Processing started'
        );

        $this->audioProcessingService
            ->method('processSermon')
            ->with($file, $clientFileDate)
            ->willReturn($expectedResult);

        $result = $this->service->processSermon($file, $clientFileDate);

        $this->assertTrue($result->success);
        $this->assertEquals('test-456', $result->processingId);
    }

    #[Test]
    public function it_delegates_process_sermon_audio_with_metadata(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024);
        $metadata = ['source' => 'livestream', 'segment_id' => 'seg-1'];
        $expectedArray = ['processing_id' => 'test-789', 'status' => 'pending'];

        $this->audioProcessingService
            ->method('processSermonAudio')
            ->with($file, $metadata)
            ->willReturn($expectedArray);

        $result = $this->service->processSermonAudio($file, $metadata);

        $this->assertEquals('test-789', $result['processing_id']);
        $this->assertEquals('pending', $result['status']);
    }

    #[Test]
    public function it_delegates_get_processing_status_to_status_management_service(): void
    {
        $processingId = 'test-status-123';
        $expectedResponse = StandardProcessingResponse::found(
            processingId: $processingId,
            status: 'processing',
            currentStep: 'transcribing_audio',
            progressPercentage: 70
        );

        $this->statusManagementService
            ->method('getProcessingStatus')
            ->with($processingId)
            ->willReturn($expectedResponse);

        $response = $this->service->getProcessingStatus($processingId);

        $this->assertTrue($response->found);
        $this->assertEquals($processingId, $response->processingId);
        $this->assertEquals('processing', $response->status);
        $this->assertEquals(70, $response->progressPercentage);
    }

    #[Test]
    public function it_delegates_get_processing_statistics(): void
    {
        $expectedStats = [
            'total' => 50,
            'completed' => 45,
            'failed' => 5,
            'success_rate' => 90.0,
        ];

        $this->statusManagementService
            ->method('getProcessingStatistics')
            ->willReturn($expectedStats);

        $stats = $this->service->getProcessingStatistics();

        $this->assertEquals(50, $stats['total']);
        $this->assertEquals(90.0, $stats['success_rate']);
    }

    #[Test]
    public function it_delegates_retry_processing_to_job_pipeline_service(): void
    {
        $processingId = 'retry-123';
        $expectedResult = ProcessingResult::success(
            processingId: $processingId,
            message: 'Retry initiated'
        );

        $this->jobPipelineService
            ->method('retryProcessing')
            ->with($processingId)
            ->willReturn($expectedResult);

        $result = $this->service->retryProcessing($processingId);

        $this->assertTrue($result->success);
        $this->assertEquals($processingId, $result->processingId);
    }

    #[Test]
    public function it_delegates_get_failed_processing_logs(): void
    {
        $expectedLogs = [
            ['processing_id' => 'fail-1', 'error' => 'Transcription failed'],
            ['processing_id' => 'fail-2', 'error' => 'Storage error'],
        ];

        $this->statusManagementService
            ->method('getFailedProcessingLogs')
            ->with(50)
            ->willReturn($expectedLogs);

        $logs = $this->service->getFailedProcessingLogs();

        $this->assertCount(2, $logs);
    }

    #[Test]
    public function it_passes_custom_limit_to_get_failed_processing_logs(): void
    {
        $this->statusManagementService
            ->method('getFailedProcessingLogs')
            ->with(10)
            ->willReturn([]);

        $logs = $this->service->getFailedProcessingLogs(10);

        $this->assertEmpty($logs);
    }

    #[Test]
    public function it_delegates_mark_for_manual_review(): void
    {
        $this->statusManagementService
            ->method('markForManualReview')
            ->with('review-123', 'Needs attention')
            ->willReturn(true);

        $result = $this->service->markForManualReview('review-123', 'Needs attention');

        $this->assertTrue($result);
    }

    #[Test]
    public function it_delegates_get_detailed_processing_logs(): void
    {
        $expectedLogs = [
            ['step' => 'transcription', 'status' => 'completed'],
            ['step' => 'analysis', 'status' => 'failed'],
        ];

        $this->statusManagementService
            ->method('getDetailedProcessingLogs')
            ->with('detail-123')
            ->willReturn($expectedLogs);

        $logs = $this->service->getDetailedProcessingLogs('detail-123');

        $this->assertCount(2, $logs);
        $this->assertEquals('transcription', $logs[0]['step']);
    }

    #[Test]
    public function it_delegates_get_system_health(): void
    {
        $expectedHealth = [
            'status' => 'healthy',
            'disk_space' => '50GB',
            'queue_size' => 3,
        ];

        $this->statusManagementService
            ->method('getSystemHealth')
            ->willReturn($expectedHealth);

        $health = $this->service->getSystemHealth();

        $this->assertEquals('healthy', $health['status']);
        $this->assertArrayHasKey('queue_size', $health);
    }

    // --- Graceful Degradation Tests ---

    #[Test]
    public function it_applies_graceful_degradation_successfully(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Untitled Sermon']);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $fallbackData = [
            'title' => 'Sunday Morning Sermon',
            'slug' => 'sunday-morning-sermon',
            'series' => null,
            'reference' => null,
            'points' => ['Main Message'],
        ];

        $this->validationService
            ->method('generateFallbackData')
            ->willReturn($fallbackData);

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Graceful degradation applied', $result->message);
        $this->assertEquals($log->processing_id, $result->processingId);
        $this->assertEquals($sermon->id, $result->details['sermon_id']);
        $this->assertArrayHasKey('applied_fallbacks', $result->details);
        $this->assertArrayHasKey('degradation_applied_at', $result->details);
    }

    #[Test]
    public function it_updates_sermon_with_fallback_data_on_degradation(): void
    {
        $sermon = Sermon::factory()->create(['title' => 'Untitled']);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $fallbackData = [
            'title' => 'Fallback Title',
            'slug' => 'fallback-title',
            'series' => null,
            'reference' => null,
            'points' => ['Main Message'],
        ];

        $this->validationService
            ->method('generateFallbackData')
            ->willReturn($fallbackData);

        $this->service->applyGracefulDegradation($log->processing_id);

        $sermon->refresh();
        $this->assertEquals('Fallback Title', $sermon->title);
        $this->assertEquals('fallback-title', $sermon->slug);
    }

    #[Test]
    public function it_marks_processing_as_completed_with_degradation(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $this->validationService
            ->method('generateFallbackData')
            ->willReturn(['title' => 'Fallback', 'slug' => 'fallback', 'series' => null, 'reference' => null, 'points' => ['Main Message']]);

        $this->service->applyGracefulDegradation($log->processing_id);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::COMPLETED, $log->status);
        $this->assertEquals('completed_with_degradation', $log->current_step);
        $this->assertEquals('Graceful degradation applied', $log->error_message);
    }

    #[Test]
    public function it_returns_failure_when_processing_log_not_found_for_degradation(): void
    {
        $result = $this->service->applyGracefulDegradation('nonexistent-id');

        $this->assertFalse($result->success);
        $this->assertEquals('PROCESSING_LOG_NOT_FOUND', $result->errorCode);
        $this->assertStringContainsString('Processing log not found', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_no_sermon_id_for_degradation(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => null,
        ]);

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('NO_SERMON_RECORD', $result->errorCode);
    }

    #[Test]
    public function it_returns_failure_when_sermon_not_found_for_degradation(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => null,
        ]);

        // Set sermon_id to a non-existent value bypassing FK constraint
        \Illuminate\Support\Facades\DB::statement(
            'SET FOREIGN_KEY_CHECKS=0'
        );
        $log->update(['sermon_id' => 99999]);
        \Illuminate\Support\Facades\DB::statement(
            'SET FOREIGN_KEY_CHECKS=1'
        );

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('SERMON_NOT_FOUND', $result->errorCode);
    }

    #[Test]
    public function it_handles_exception_during_graceful_degradation(): void
    {
        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'sermon_id' => $sermon->id,
        ]);

        $this->validationService
            ->method('generateFallbackData')
            ->willThrowException(new \RuntimeException('Validation service error'));

        $result = $this->service->applyGracefulDegradation($log->processing_id);

        $this->assertFalse($result->success);
        $this->assertEquals('DEGRADATION_FAILED', $result->errorCode);
        $this->assertStringContainsString('Validation service error', $result->message);
    }

    // --- Cancel Processing Tests ---

    #[Test]
    public function it_cancels_processing_successfully(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertTrue($result);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::CANCELLED, $log->status);
        $this->assertEquals('cancelled', $log->current_step);
        $this->assertEquals('Processing cancelled by user', $log->error_message);
    }

    #[Test]
    public function it_cancels_pending_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertTrue($result);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::CANCELLED, $log->status);
    }

    #[Test]
    public function it_returns_false_when_cancelling_nonexistent_processing(): void
    {
        $result = $this->service->cancelProcessing('nonexistent-id');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_when_cancelling_completed_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertFalse($result);

        $log->refresh();
        $this->assertEquals(ProcessingStatus::COMPLETED, $log->status);
    }

    #[Test]
    public function it_returns_false_when_cancelling_already_failed_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create();

        // Failed processing still gets "cancelled" since it's not COMPLETED
        $result = $this->service->cancelProcessing($log->processing_id);

        $this->assertTrue($result);
    }
}
