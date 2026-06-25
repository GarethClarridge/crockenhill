<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\GetMediaProcessingStatus;
use App\Data\ProcessingResult;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Jobs\UpdateSermonRecord;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Processing\MediaValidationService;
use App\Services\Processing\MetadataExtractionService;
use App\Services\Processing\ProcessingInitiator;
use App\Services\Processing\ProcessingLogService;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Services\Sermon\LivestreamSegmentationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnifiedMediaProcessorTest extends TestCase
{
    use RefreshDatabase;

    private UnifiedMediaProcessor $processor;

    private LivestreamSegmentationService $livestreamService;

    private ProcessingPipelineBuilder $pipelineBuilder;

    private ProcessingLogService $processingLogService;

    private ProcessingInitiator $processingInitiator;

    private MetadataExtractionService $metadataService;

    private MediaValidationService $mediaValidation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->livestreamService = $this->createMock(LivestreamSegmentationService::class);
        $this->pipelineBuilder = $this->createStub(ProcessingPipelineBuilder::class);
        $this->processingLogService = $this->createStub(ProcessingLogService::class);
        $this->processingInitiator = $this->createMock(ProcessingInitiator::class);
        $this->metadataService = $this->createStub(MetadataExtractionService::class);
        $this->mediaValidation = $this->createStub(MediaValidationService::class);

        $this->app->instance(LivestreamSegmentationService::class, $this->livestreamService);
        $this->app->instance(ProcessingPipelineBuilder::class, $this->pipelineBuilder);
        $this->app->instance(ProcessingLogService::class, $this->processingLogService);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        $this->processor = new UnifiedMediaProcessor(
            $this->processingInitiator,
            $this->metadataService,
            $this->mediaValidation,
            app(ProcessingRunOrchestrator::class),
            app(GetMediaProcessingStatus::class),
        );
    }

    // --- process() routing tests ---

    #[Test]
    public function it_processes_audio_file_successfully(): void
    {
        Bus::fake();
        Storage::fake('public');

        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $this->metadataService
            ->method('extractId3Metadata')
            ->willReturn(['title' => 'Test Sermon', 'artist' => 'Test Preacher']);

        $processingLog = MediaProcessingLog::factory()->audio()->pending()->create([
            'original_filename' => 'sermon.mp3',
            'current_step' => 'audio_processing_initiated',
        ]);

        $this->processingInitiator
            ->method('initiateProcessing')
            ->willReturn($processingLog);

        $this->pipelineBuilder
            ->method('buildAudioPipeline')
            ->willReturn([new AudioStubJob]);

        $result = $this->processor->process('audio', $file);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->processingId);
        $this->assertStringContainsString('Audio processing initiated', $result->message);

        $log = MediaProcessingLog::where('processing_id', $result->processingId)->first();
        $this->assertNotNull($log);
        $this->assertEquals(MediaType::Audio, $log->processing_type);
        $this->assertEquals('sermon.mp3', $log->original_filename);
        $this->assertEquals(ProcessingStatus::Pending, $log->status);
    }

    #[Test]
    public function it_processes_audio_with_client_file_date(): void
    {
        Bus::fake();
        Storage::fake('public');

        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $this->metadataService
            ->method('extractId3Metadata')
            ->willReturn([]);

        $processingLog = MediaProcessingLog::factory()->audio()->pending()->create([
            'original_filename' => 'sermon.mp3',
        ]);

        $this->processingInitiator
            ->method('initiateProcessing')
            ->willReturn($processingLog);

        $this->pipelineBuilder
            ->method('buildAudioPipeline')
            ->willReturn([new AudioStubJob]);

        $result = $this->processor->process('audio', $file, '2026-02-01');

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_returns_failure_when_audio_processing_throws_exception(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

        $this->metadataService
            ->method('extractId3Metadata')
            ->willThrowException(new \RuntimeException('Metadata extraction failed'));

        $result = $this->processor->process('audio', $file);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('An internal error occurred while initiating audio processing.', $result->message);
        $this->assertEquals('AUDIO_PROCESSING_INITIATION_FAILED', $result->errorCode);
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
            ->expects($this->once())
            ->method('startProcessing')
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

    #[Test]
    public function non_admin_user_can_only_read_owned_processing_logs(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);

        $ownedLog = MediaProcessingLog::factory()->audio()->processing()->create([
            'owner_user_id' => $owner->id,
        ]);
        $otherLog = MediaProcessingLog::factory()->audio()->processing()->create([
            'owner_user_id' => $otherUser->id,
        ]);

        $this->actingAs($owner);

        $ownedResponse = $this->processor->getStatus($ownedLog->processing_id);
        $otherResponse = $this->processor->getStatus($otherLog->processing_id);

        $this->assertTrue($ownedResponse->found);
        $this->assertFalse($otherResponse->found);
    }

    #[Test]
    public function admin_user_can_read_any_processing_log(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create(['is_admin' => false]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'owner_user_id' => $owner->id,
        ]);

        $this->actingAs($admin);

        $response = $this->processor->getStatus($log->processing_id);

        $this->assertTrue($response->found);
        $this->assertEquals($log->processing_id, $response->processingId);
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
    public function it_cancels_audio_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->processing()->create();

        $result = $this->processor->cancel($log->processing_id);

        $this->assertTrue($result['success']);
        $this->assertEquals('Processing cancelled successfully', $result['message']);
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $log->processing_id,
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_cancels_video_processing(): void
    {
        $log = MediaProcessingLog::factory()->video()->processing()->create();

        $result = $this->processor->cancel($log->processing_id);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('media_processing_logs', [
            'processing_id' => $log->processing_id,
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_cancels_livestream_processing_via_the_orchestrator(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => null,
        ]);

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
    public function it_returns_failure_when_cancelling_completed_audio_processing(): void
    {
        $log = MediaProcessingLog::factory()->audio()->completed()->create();

        $result = $this->processor->cancel($log->processing_id);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to cancel processing', $result['message']);
    }

    #[Test]
    public function non_admin_user_cannot_cancel_other_users_processing(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);
        $log = MediaProcessingLog::factory()->audio()->processing()->create([
            'owner_user_id' => $otherUser->id,
        ]);

        $this->actingAs($owner);

        $result = $this->processor->cancel($log->processing_id);

        $this->assertFalse($result['success']);
        $this->assertEquals('Processing ID not found', $result['message']);
    }

    // --- retry() tests ---

    #[Test]
    public function it_retries_audio_processing_via_the_orchestrator(): void
    {
        Bus::fake();

        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'transcribing_audio_failed',
        ]);

        $this->pipelineBuilder
            ->method('buildAudioPipeline')
            ->willReturn((new ProcessingPipelineBuilder)->buildAudioPipeline($log));

        $result = $this->processor->retry($log->processing_id);

        $this->assertTrue($result->success);
        $this->assertEquals($log->processing_id, $result->processingId);
    }

    #[Test]
    public function it_retries_video_processing_via_the_orchestrator(): void
    {
        Bus::fake();

        $log = MediaProcessingLog::factory()->video()->failed()->create([
            'current_step' => 'transcribing_audio_failed',
        ]);

        $this->pipelineBuilder
            ->method('buildDirectVideoPipeline')
            ->willReturn((new ProcessingPipelineBuilder)->buildDirectVideoPipeline($log));

        $result = $this->processor->retry($log->processing_id);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function it_retries_livestream_processing_via_the_orchestrator(): void
    {
        Bus::fake();

        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'current_step' => 'transcribing_audio_failed',
        ]);

        $this->pipelineBuilder
            ->method('buildLivestreamParallelJobs')
            ->willReturn([new AudioStubJob]);

        $this->pipelineBuilder
            ->method('buildLivestreamChainJobs')
            ->willReturn((new ProcessingPipelineBuilder)->buildLivestreamChainJobs($log));

        $result = $this->processor->retry($log->processing_id);

        $this->assertTrue($result->success);
        $this->assertEquals($log->processing_id, $result->processingId);
        $this->assertStringContainsString('Processing retry initiated successfully', $result->message);
    }

    #[Test]
    public function it_marks_unknown_audio_retry_steps_for_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'current_step' => 'creating_sermon_record_failed',
        ]);

        $result = $this->processor->retry($log->processing_id);

        $this->assertTrue($result->success);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame('manual_review_required', $log->current_step);
    }

    #[Test]
    public function it_returns_failure_when_retry_processing_id_not_found(): void
    {
        $result = $this->processor->retry('nonexistent-id');

        $this->assertFalse($result->success);
        $this->assertEquals('NOT_FOUND', $result->errorCode);
    }

    #[Test]
    public function non_admin_user_cannot_retry_other_users_processing(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);
        $log = MediaProcessingLog::factory()->audio()->failed()->create([
            'owner_user_id' => $otherUser->id,
        ]);

        $this->actingAs($owner);

        $result = $this->processor->retry($log->processing_id);

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

    #[Test]
    public function non_admin_user_can_handle_only_owned_processing_id(): void
    {
        $owner = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create(['is_admin' => false]);
        $ownedLog = MediaProcessingLog::factory()->audio()->processing()->create([
            'owner_user_id' => $owner->id,
        ]);
        $otherLog = MediaProcessingLog::factory()->audio()->processing()->create([
            'owner_user_id' => $otherUser->id,
        ]);

        $this->actingAs($owner);

        $this->assertTrue($this->processor->canHandle($ownedLog->processing_id));
        $this->assertFalse($this->processor->canHandle($otherLog->processing_id));
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
            ->willReturnCallback(function ($processingLog) {
                $sermon = Sermon::factory()->create();

                return [new UpdateSermonRecord($sermon->id)];
            });

        $result = $this->processor->process('video', $file);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Video processing initiated', $result->message);
    }

    #[Test]
    public function it_rejects_auto_trim_video_processing_when_the_feature_is_disabled(): void
    {
        config(['media-processing.video_auto_trim.enabled' => false]);

        $file = UploadedFile::fake()->create('sermon-video.mp4', 2048);

        $result = $this->processor->process('video', $file, null, [
            'auto_trim' => true,
            'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('AUTO_TRIM_DISABLED', $result->errorCode);
        $this->assertStringContainsString('disabled', $result->message);
    }

    #[Test]
    public function it_persists_auto_trim_video_processing_metadata_for_direct_video_runs(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->create('sermon-video.mp4', 2048);

        $processingLog = MediaProcessingLog::factory()->video()->pending()->create([
            'original_filename' => 'sermon-video.mp4',
            'current_step' => 'video_processing_initiated',
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);

        $this->processingInitiator
            ->expects($this->once())
            ->method('initiateProcessing')
            ->with(
                $file,
                MediaType::Video,
                null,
                $this->callback(function (array $attributes): bool {
                    return ($attributes['processing_metadata']['video_processing_mode'] ?? null) === MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM
                        && ($attributes['processing_metadata']['trim_requested'] ?? null) === true;
                })
            )
            ->willReturn($processingLog);

        $this->pipelineBuilder
            ->method('buildAutoTrimVideoPipeline')
            ->willReturn([new AudioStubJob]);

        $result = $this->processor->process('video', $file, null, [
            'auto_trim' => true,
            'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
        ]);

        $this->assertTrue($result->success);
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
            ->willReturnCallback(function ($processingLog) {
                $sermon = Sermon::factory()->create();

                return [new UpdateSermonRecord($sermon->id)];
            });

        $this->processor->process('video', $file);

        $this->assertDatabaseHas('media_processing_logs', [
            'processing_type' => 'video',
            'original_filename' => 'sermon-video.mp4',
            'status' => ProcessingStatus::Pending->value,
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
        $this->assertStringContainsString('An internal error occurred while initiating video processing.', $result->message);
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
            ->willReturnCallback(function ($processingLog) {
                $sermon = Sermon::factory()->create();

                return [new UpdateSermonRecord($sermon->id)];
            });

        $this->processor->process('video', $file);

        $log = MediaProcessingLog::where('original_filename', '2026-02-10.mp4')->first();

        $this->assertNotNull($log);
        $this->assertEquals('2026-02-10', $log->processing_metadata['extracted_date']);
        $this->assertEquals('evening', $log->processing_metadata['extracted_service']);
    }
}

class AudioStubJob implements ShouldQueue
{
    use Dispatchable, \Illuminate\Queue\InteractsWithQueue, Queueable;

    public function handle(): void {}
}
