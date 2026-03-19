<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProvidesSafeMessage;
use App\Data\SermonMetadata;
use App\Data\StandardProcessingResponse;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UnifiedMediaProcessor
{
    public function __construct(
        private readonly SermonJobPipelineService $jobPipelineService,
        private readonly SermonProcessingLogger $sermonProcessingLogger,
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly ProcessingLogService $processingLogService,
        private readonly ProcessingInitiator $processingInitiator,
        private readonly MetadataExtractionService $metadataService,
        private readonly MediaValidationService $mediaValidation
    ) {}

    public function process(string $type, UploadedFile $file, ?string $clientFileDate = null): ProcessingResult
    {
        Log::info('Unified media processing started', [
            'type' => $type,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'client_file_date' => $clientFileDate,
        ]);

        $mediaType = MediaType::tryFrom($type);

        if ($mediaType === null) {
            return ProcessingResult::failure(
                processingId: 'invalid-'.Str::uuid(),
                message: "Unsupported media type: {$type}",
                errorCode: 'UNSUPPORTED_TYPE'
            );
        }

        $fileHash = $this->computeFileHash($file);
        $duplicate = $this->findActiveDuplicate($fileHash);

        if ($duplicate !== null) {
            return $duplicate;
        }

        return match ($mediaType) {
            MediaType::Audio => $this->processAudio($file, $clientFileDate, $fileHash),
            MediaType::Video => $this->processDirectVideo($file, $clientFileDate, $fileHash),
            MediaType::Livestream => $this->livestreamService()->startProcessing($file, $clientFileDate, $fileHash),
        };
    }

    public function getStatus(string $processingId): StandardProcessingResponse
    {
        $log = $this->findProcessingLog($processingId);

        if (! $log) {
            return StandardProcessingResponse::notFound();
        }

        return StandardProcessingResponse::fromProcessingLog($log);
    }

    public function getStatusWithLogs(string $processingId, bool $includeLogs = false, int $logLimit = 20): StandardProcessingResponse
    {
        $baseStatus = $this->getStatus($processingId);

        if (! $baseStatus->found || ! $includeLogs) {
            return $baseStatus;
        }

        $logs = $this->processingLogService->getProcessingLogs($processingId, $logLimit);
        $metrics = $this->processingLogService->getPerformanceMetrics($processingId);

        if ($baseStatus->processingId === null || $baseStatus->status === null) {
            return $baseStatus;
        }

        return StandardProcessingResponse::withLogs(
            processingId: $baseStatus->processingId,
            status: $baseStatus->status,
            currentStep: $baseStatus->currentStep,
            progressPercentage: $baseStatus->progressPercentage,
            errorMessage: $baseStatus->errorMessage,
            sermonId: $baseStatus->sermonId,
            sermonUrl: $baseStatus->sermonUrl,
            startedAt: $baseStatus->startedAt,
            updatedAt: $baseStatus->updatedAt,
            estimatedCompletion: $baseStatus->estimatedCompletion,
            additionalData: $baseStatus->additionalData,
            logs: $logs,
            metrics: $metrics
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function cancel(string $processingId): array
    {
        $log = $this->findProcessingLog($processingId);

        if (! $log) {
            return ['success' => false, 'message' => 'Processing ID not found'];
        }

        $result = match ($log->processing_type) {
            MediaType::Audio, MediaType::Video => $this->cancelSermonProcessing($processingId, $log),
            MediaType::Livestream => $this->livestreamService()->cancelProcessing($processingId),
        };

        return [
            'success' => $result,
            'message' => $result ? 'Processing cancelled successfully' : 'Failed to cancel processing',
        ];
    }

    public function retry(string $processingId): ProcessingResult
    {
        $log = $this->findProcessingLog($processingId);

        if (! $log) {
            return ProcessingResult::failure(
                processingId: $processingId,
                message: 'Processing ID not found for retry',
                errorCode: 'NOT_FOUND'
            );
        }

        return match ($log->processing_type) {
            MediaType::Audio, MediaType::Video => $this->jobPipelineService->retryProcessing($processingId),
            MediaType::Livestream => $this->convertLivestreamRetryResult($this->livestreamService()->retryProcessing($processingId)),
        };
    }

    private function cancelSermonProcessing(string $processingId, MediaProcessingLog $log): bool
    {
        try {
            if ($log->status === ProcessingStatus::COMPLETED) {
                return false;
            }

            $log->markAsCancelled('Processing cancelled by user');
            $this->sermonProcessingLogger->logProcessingComplete($processingId, ProcessingStatus::CANCELLED, [], 'Processing cancelled by user');

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cancel processing', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function convertLivestreamRetryResult(\App\Data\LivestreamProcessingResult $livestreamResult): ProcessingResult
    {
        if ($livestreamResult->errorMessage) {
            return ProcessingResult::failure(
                processingId: $livestreamResult->processingId,
                message: $livestreamResult->errorMessage,
                errorCode: 'RETRY_FAILED'
            );
        }

        return ProcessingResult::success(
            processingId: $livestreamResult->processingId,
            message: 'Livestream processing retry initiated successfully'
        );
    }

    public function canHandle(string $processingId): bool
    {
        return $this->processingLogQuery()
            ->where('processing_id', $processingId)
            ->exists();
    }

    private function findProcessingLog(string $processingId): ?MediaProcessingLog
    {
        return $this->processingLogQuery()
            ->where('processing_id', $processingId)
            ->first();
    }

    private function livestreamService(): LivestreamSegmentationService
    {
        return app(LivestreamSegmentationService::class);
    }

    /**
     * @return Builder<MediaProcessingLog>
     */
    private function processingLogQuery(): Builder
    {
        $query = MediaProcessingLog::query();
        $user = Auth::user();

        if ($user instanceof User) {
            $query->visibleTo($user);
        }

        return $query;
    }

    /**
     * Process an audio file through the complete automation pipeline.
     * Uses ProcessingPipelineBuilder for consistent job chain pattern (same as video processing).
     */
    private function processAudio(UploadedFile $file, ?string $clientFileDate, ?string $fileHash): ProcessingResult
    {
        try {
            Log::info('Starting audio processing', [
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'client_file_date' => $clientFileDate,
            ]);

            $processingId = (string) Str::uuid();

            $this->mediaValidation->validateUploadedFile(MediaType::Audio, $file);

            $metadata = SermonMetadata::fromUploadedFile($file);
            $storedFilePath = $this->storeAudioFile($file, $metadata);

            $id3Metadata = $this->metadataService->extractId3Metadata($file);

            Log::info('Audio file stored, creating processing log', [
                'processing_id' => $processingId,
                'stored_path' => $storedFilePath,
                'id3_metadata' => $id3Metadata,
            ]);

            $processingLog = MediaProcessingLog::create([
                'processing_id' => $processingId,
                'processing_type' => MediaType::Audio,
                'original_filename' => $file->getClientOriginalName(),
                'file_hash' => $fileHash,
                'owner_user_id' => Auth::id(),
                'source_file_path' => $storedFilePath,
                'status' => ProcessingStatus::PENDING,
                'current_step' => 'audio_processing_initiated',
                'processing_metadata' => [
                    'id3_metadata' => $id3Metadata,
                ],
            ]);

            $jobs = $this->pipelineBuilder->buildAudioPipeline($processingLog);

            Log::info('Audio processing pipeline created', [
                'processing_id' => $processingId,
                'jobs_count' => count($jobs),
                'job_classes' => array_map(fn ($job) => get_class($job), $jobs),
            ]);

            Bus::chain($jobs)
                ->catch(function (\Throwable $e) use ($processingLog) {
                    Log::error('Audio processing failed in job chain', [
                        'processing_id' => $processingLog->processing_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $message = $e instanceof ProvidesSafeMessage
                        ? $e->getSafeMessage()
                        : 'An internal error occurred during audio processing.';

                    $processingLog->update([
                        'status' => ProcessingStatus::FAILED,
                        'error_message' => "Audio processing failed: {$message}",
                    ]);
                })
                ->onQueue($this->audioQueue())
                ->dispatch();

            Log::info('Audio processing jobs dispatched', [
                'processing_id' => $processingId,
            ]);

            return ProcessingResult::success(
                processingId: $processingId,
                message: 'Audio processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingId])
            );

        } catch (\Exception $e) {
            Log::error('Failed to initiate audio processing', [
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e instanceof ProvidesSafeMessage
                ? $e->getSafeMessage()
                : 'An internal error occurred while initiating audio processing.';

            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: "Failed to initiate audio processing: {$message}",
                errorCode: 'AUDIO_PROCESSING_INITIATION_FAILED'
            );
        }
    }

    private function storeAudioFile(UploadedFile $file, SermonMetadata $metadata): string
    {
        $disk = config('media-processing.storage.sermon_disk', 'public');
        $basePath = config('media-processing.storage.paths.audio', 'sermons');

        $directory = $basePath.'/'.$metadata->date->format('Y/m');

        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().'.'.$extension;

        $path = $file->storeAs($directory, $filename, $disk);

        if (! $path) {
            throw new \RuntimeException('Failed to store audio file');
        }

        return $path;
    }

    private function audioQueue(): string
    {
        return (string) config('media-processing.queues.audio', 'audio-processing');
    }

    private function computeFileHash(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            return null;
        }

        $hash = hash_file('sha256', $realPath);

        return $hash !== false ? $hash : null;
    }

    /**
     * Look up an in-progress log with a matching hash.
     *
     * Note: there is a narrow TOCTOU window between this check and the subsequent
     * log creation. Two concurrent uploads of identical files could both pass and
     * each create their own log. The consequence is a duplicate processing run —
     * not data corruption — and the window is narrow enough to be an accepted risk.
     */
    private function findActiveDuplicate(?string $fileHash): ?ProcessingResult
    {
        if ($fileHash === null) {
            return null;
        }

        $existingLog = MediaProcessingLog::query()
            ->where('file_hash', $fileHash)
            ->whereIn('status', [ProcessingStatus::PENDING->value, ProcessingStatus::PROCESSING->value])
            ->latest()
            ->first();

        if ($existingLog === null) {
            return null;
        }

        Log::info('Duplicate upload detected, reusing existing processing run', [
            'file_hash' => $fileHash,
            'existing_processing_id' => $existingLog->processing_id,
            'processing_type' => $existingLog->processing_type->value,
        ]);

        return ProcessingResult::success(
            processingId: $existingLog->processing_id,
            message: 'Duplicate upload detected. Returning existing processing run.',
            statusUrl: route('api.media.processing.status', ['processingId' => $existingLog->processing_id])
        );
    }

    /**
     * Process video by extracting audio and joining existing sermon processing.
     * Uses ProcessingInitiator for shared metadata extraction and log creation.
     */
    private function processDirectVideo(UploadedFile $file, ?string $clientFileDate, ?string $fileHash): ProcessingResult
    {
        try {
            // Store video file temporarily before processing (preserves file timestamps for metadata extraction)
            $tempPath = $file->store('temp/video-processing');

            // Create processing log via shared initiator
            $processingLog = $this->processingInitiator->initiateProcessing(
                $file,
                MediaType::Video,
                $clientFileDate,
                ['source_file_path' => $tempPath, 'file_hash' => $fileHash]
            );

            // Build and dispatch job chain using the standardized pipeline builder
            $jobs = $this->pipelineBuilder->buildDirectVideoPipeline($processingLog);

            Bus::chain($jobs)
                ->catch(function (\Throwable $e) use ($processingLog) {
                    Log::error('Video processing failed in job chain', [
                        'processing_id' => $processingLog->processing_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $message = $e instanceof ProvidesSafeMessage
                        ? $e->getSafeMessage()
                        : 'An internal error occurred during video processing.';

                    $processingLog->update([
                        'status' => \App\Enums\ProcessingStatus::FAILED,
                        'error_message' => "Video processing failed: {$message}",
                    ]);
                })
                ->onQueue((string) config('media-processing.queues.video', config('media-processing.types.video.queue', 'video-processing')))
                ->dispatch();

            return ProcessingResult::success(
                processingId: $processingLog->processing_id,
                message: 'Video processing initiated successfully',
                statusUrl: route('api.media.processing.status', ['processingId' => $processingLog->processing_id])
            );

        } catch (\Exception $e) {
            Log::error('Failed to initiate video processing', [
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e instanceof ProvidesSafeMessage
                ? $e->getSafeMessage()
                : 'An internal error occurred while initiating video processing.';

            return ProcessingResult::failure(
                processingId: 'failed-'.Str::uuid(),
                message: "Failed to initiate video processing: {$message}",
                errorCode: 'VIDEO_PROCESSING_FAILED'
            );
        }
    }
}
