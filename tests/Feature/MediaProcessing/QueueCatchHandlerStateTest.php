<?php

declare(strict_types=1);

namespace Tests\Feature\MediaProcessing;

use App\Contracts\ProvidesSafeMessage;
use App\Jobs\CleanupTemporaryFiles;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use App\Services\LivestreamSegmentationService;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\MediaProcessingRunTransitionService;
use App\Services\MediaValidationService;
use App\Services\MetadataExtractionService;
use App\Services\ProcessingInitiator;
use App\Services\ProcessingPipelineBuilder;
use App\Services\ProcessingRunFailureHandler;
use App\Services\ProcessingRunOrchestrator;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\SerializableClosure\SerializableClosure;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueueCatchHandlerStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_the_audio_chain_catch_state_from_unified_media_processor(): void
    {
        Bus::fake();
        Storage::fake('public');

        $metadataService = $this->createMock(MetadataExtractionService::class);
        $metadataService->expects($this->once())
            ->method('extractId3Metadata')
            ->willReturn(['title' => 'Test Sermon']);

        $mediaValidation = $this->createMock(MediaValidationService::class);
        $mediaValidation->expects($this->once())
            ->method('validateUploadedFile');

        $pipelineBuilder = $this->createMock(ProcessingPipelineBuilder::class);
        $pipelineBuilder->expects($this->once())
            ->method('buildAudioPipeline')
            ->willReturnCallback(
                fn (MediaProcessingLog $log): array => [new CleanupTemporaryFiles($log)]
            );

        $this->app->instance(MetadataExtractionService::class, $metadataService);
        $this->app->instance(MediaValidationService::class, $mediaValidation);
        $this->app->instance(ProcessingPipelineBuilder::class, $pipelineBuilder);
        $this->app->forgetInstance(UnifiedMediaProcessor::class);

        $processor = $this->app->make(UnifiedMediaProcessor::class);
        $result = $processor->process(
            'audio',
            UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg')
        );

        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $result->processingId)
            ->firstOrFail();

        $catchCallbacks = [];

        Bus::assertDispatched(CleanupTemporaryFiles::class, function (CleanupTemporaryFiles $job) use (&$catchCallbacks): bool {
            $catchCallbacks = $job->chainCatchCallbacks ?? [];

            return true;
        });

        $this->assertCount(1, $catchCallbacks);

        $callback = $this->unwrapCallback($catchCallbacks[0]);
        $callback(new SafeQueueCatchException('Audio pipeline blew up', 'Transcription service unavailable'));

        $processingLog->refresh();

        $this->assertSame('failed', $processingLog->status->value);
        $this->assertSame('audio_processing_initiated', $processingLog->current_step);
        $this->assertSame(
            'Audio processing failed: Transcription service unavailable',
            $processingLog->error_message
        );
        $this->assertNull($processingLog->completed_at);
    }

    #[Test]
    public function it_does_not_overwrite_a_cancelled_audio_run_when_a_late_chain_failure_arrives(): void
    {
        Bus::fake();
        Storage::fake('public');

        $metadataService = $this->createMock(MetadataExtractionService::class);
        $metadataService->expects($this->once())
            ->method('extractId3Metadata')
            ->willReturn(['title' => 'Test Sermon']);

        $mediaValidation = $this->createMock(MediaValidationService::class);
        $mediaValidation->expects($this->once())
            ->method('validateUploadedFile');

        $pipelineBuilder = $this->createMock(ProcessingPipelineBuilder::class);
        $pipelineBuilder->expects($this->once())
            ->method('buildAudioPipeline')
            ->willReturnCallback(
                fn (MediaProcessingLog $log): array => [new CleanupTemporaryFiles($log)]
            );

        $this->app->instance(MetadataExtractionService::class, $metadataService);
        $this->app->instance(MediaValidationService::class, $mediaValidation);
        $this->app->instance(ProcessingPipelineBuilder::class, $pipelineBuilder);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);
        $this->app->forgetInstance(UnifiedMediaProcessor::class);

        $processor = $this->app->make(UnifiedMediaProcessor::class);
        $result = $processor->process(
            'audio',
            UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg')
        );

        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $result->processingId)
            ->firstOrFail();

        $catchCallbacks = [];

        Bus::assertDispatched(CleanupTemporaryFiles::class, function (CleanupTemporaryFiles $job) use (&$catchCallbacks): bool {
            $catchCallbacks = $job->chainCatchCallbacks ?? [];

            return true;
        });

        app(MediaProcessingRunTransitionService::class)->markAsCancelled(
            $processingLog,
            'Processing cancelled by user'
        );

        $callback = $this->unwrapCallback($catchCallbacks[0]);
        $callback(new SafeQueueCatchException('Audio pipeline blew up', 'Transcription service unavailable'));

        $processingLog->refresh();

        $this->assertSame('cancelled', $processingLog->status->value);
        $this->assertSame('cancelled', $processingLog->current_step);
        $this->assertSame('Processing cancelled by user', $processingLog->error_message);
        $this->assertNotNull($processingLog->completed_at);
    }

    #[Test]
    public function it_persists_the_livestream_batch_catch_state(): void
    {
        Bus::fake();
        Mail::fake();

        $processingLog = $this->createLivestreamProcessingLog('livestream-batch-catch');
        $service = $this->makeLivestreamServiceForCatchTests($processingLog);

        $service->startProcessing(
            UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4')
        );

        $pendingBatch = null;

        Bus::assertBatched(function (PendingBatch $batch) use (&$pendingBatch): bool {
            $pendingBatch = $batch;

            return true;
        });

        $this->assertInstanceOf(PendingBatch::class, $pendingBatch);

        $catchCallbacks = $pendingBatch->catchCallbacks();
        $this->assertCount(1, $catchCallbacks);

        $callback = $this->unwrapCallback($catchCallbacks[0]);
        $callback(
            Bus::dispatchFakeBatch('livestream-batch-catch-test'),
            new SafeQueueCatchException('Parallel phase failed', 'Livestream batch failed safely')
        );

        $processingLog->refresh();

        $this->assertSame('failed', $processingLog->status->value);
        $this->assertSame('rms_generation', $processingLog->current_step);
        $this->assertSame('Livestream batch failed safely', $processingLog->error_message);
        $this->assertNotNull($processingLog->completed_at);

        Mail::assertQueued(LivestreamProcessingFailed::class);
    }

    #[Test]
    public function it_persists_the_livestream_post_batch_chain_catch_state(): void
    {
        Bus::fake();
        Mail::fake();

        $processingLog = $this->createLivestreamProcessingLog('livestream-chain-catch');
        $service = $this->makeLivestreamServiceForCatchTests($processingLog);

        $service->startProcessing(
            UploadedFile::fake()->create('livestream.mp4', 50000, 'video/mp4')
        );

        $pendingBatch = null;

        Bus::assertBatched(function (PendingBatch $batch) use (&$pendingBatch): bool {
            $pendingBatch = $batch;

            return true;
        });

        $this->assertInstanceOf(PendingBatch::class, $pendingBatch);

        $thenCallbacks = $pendingBatch->thenCallbacks();
        $this->assertCount(1, $thenCallbacks);

        $thenCallback = $this->unwrapCallback($thenCallbacks[0]);
        $thenCallback(Bus::dispatchFakeBatch('livestream-post-batch-chain-test'));

        $chainCatchCallbacks = [];

        Bus::assertDispatched(CleanupTemporaryFiles::class, function (CleanupTemporaryFiles $job) use (&$chainCatchCallbacks): bool {
            $chainCatchCallbacks = $job->chainCatchCallbacks ?? [];

            return true;
        });

        $this->assertCount(1, $chainCatchCallbacks);

        $chainCatch = $this->unwrapCallback($chainCatchCallbacks[0]);
        $chainCatch(new SafeQueueCatchException('Chain phase failed', 'Livestream chain failed safely'));

        $processingLog->refresh();

        $this->assertSame('failed', $processingLog->status->value);
        $this->assertSame('rms_generation', $processingLog->current_step);
        $this->assertSame('Livestream chain failed safely', $processingLog->error_message);
        $this->assertNotNull($processingLog->completed_at);

        Mail::assertQueued(LivestreamProcessingFailed::class);
    }

    private function createLivestreamProcessingLog(string $processingId): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->pending()->create([
            'processing_id' => $processingId,
            'current_step' => 'rms_generation',
            'source_file_path' => 'livestreams/source.mp4',
            'processing_metadata' => [
                'extracted_segment_path' => 'livestreams/segment.mp4',
                'extracted_audio_path' => 'livestreams/audio.mp3',
                'temp_video_path' => 'livestreams/temp.mp4',
            ],
        ]);
    }

    private function makeLivestreamServiceForCatchTests(MediaProcessingLog $processingLog): LivestreamSegmentationService
    {
        Config::set('media-processing.email.admin_email', 'admin@example.com');

        $storageService = $this->createMock(VideoStorageService::class);
        $storageService->expects($this->once())
            ->method('validateStorageSpace')
            ->with($this->greaterThan(0))
            ->willReturn(true);
        $storageService->expects($this->once())
            ->method('storeUploadedVideo')
            ->willReturn([
                'original_filename' => 'livestream.mp4',
                'temp_path' => 'livestreams/source.mp4',
                'full_path' => '/tmp/livestream.mp4',
                'file_size' => 50000,
                'mime_type' => 'video/mp4',
            ]);
        $storageService->expects($this->once())
            ->method('cleanupTemporaryFiles')
            ->with([
                'livestreams/source.mp4',
                'livestreams/segment.mp4',
                'livestreams/audio.mp3',
                'livestreams/temp.mp4',
            ]);

        $segmentationService = $this->createMock(VideoSegmentationService::class);
        $segmentationService->expects($this->once())
            ->method('validateVideoFile')
            ->with('/tmp/livestream.mp4')
            ->willReturn(true);
        $segmentationService->expects($this->once())
            ->method('getVideoMetadata')
            ->with('/tmp/livestream.mp4')
            ->willReturn([
                'duration' => 3600.0,
                'format' => 'mp4',
                'size' => 50000,
            ]);

        $pipelineBuilder = $this->createMock(ProcessingPipelineBuilder::class);
        $pipelineBuilder->expects($this->once())
            ->method('buildLivestreamParallelJobs')
            ->with($processingLog)
            ->willReturn([new CleanupTemporaryFiles($processingLog)]);
        $pipelineBuilder->expects($this->once())
            ->method('buildLivestreamChainJobs')
            ->with($processingLog)
            ->willReturn([new CleanupTemporaryFiles($processingLog)]);

        $processingInitiator = $this->createMock(ProcessingInitiator::class);
        $processingInitiator->expects($this->once())
            ->method('initiateProcessing')
            ->willReturn($processingLog);

        // Bind the storage mock so the orchestrator/failure handler cleanup path uses it.
        $this->app->instance(VideoStorageService::class, $storageService);
        $this->app->instance(ProcessingPipelineBuilder::class, $pipelineBuilder);
        $this->app->forgetInstance(ProcessingRunFailureHandler::class);
        $this->app->forgetInstance(ProcessingRunOrchestrator::class);

        return new LivestreamSegmentationService(
            $storageService,
            $segmentationService,
            $processingInitiator,
            app(ProcessingRunOrchestrator::class),
        );
    }

    private function unwrapCallback(mixed $callback): callable
    {
        if ($callback instanceof SerializableClosure) {
            return $callback->getClosure();
        }

        if (is_object($callback) && method_exists($callback, 'getClosure')) {
            return $callback->getClosure();
        }

        return $callback;
    }
}

final class SafeQueueCatchException extends \RuntimeException implements ProvidesSafeMessage
{
    public function __construct(
        string $message,
        private readonly string $safeMessage
    ) {
        parent::__construct($message);
    }

    public function getSafeMessage(): string
    {
        return $this->safeMessage;
    }
}
