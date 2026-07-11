<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Data\LivestreamProcessingResult;
use App\Data\ProcessingResult;
use App\Data\StandardProcessingResponse;
use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Media\Video\VideoStorageService;
use App\Services\Processing\ProcessingInitiator;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service for orchestrating the livestream media processing lifecycle.
 *
 * This service serves as the primary entry point for livestream video uploads,
 * managing the sequence of storage validation, temporary file persistence,
 * metadata extraction, and the initiation of the background processing pipeline.
 * It also provides methods for managing active processing runs (retry, cancel)
 * and retrieving their current status and results.
 */
class LivestreamSegmentationService
{
    use SanitizesLogData;

    public function __construct(
        private readonly VideoStorageService $storageService,
        private readonly VideoSegmentationService $segmentationService,
        private readonly ProcessingInitiator $processingInitiator,
        private readonly ProcessingRunOrchestrator $orchestrator,
    ) {}

    /**
     * Entry point for all livestream video uploads.
     *
     * Validates available storage space (requiring 2x file size), stores the
     * file temporarily, extracts initial metadata, and initiates the
     * background processing pipeline.
     *
     * @param  UploadedFile  $videoFile  The uploaded video file
     * @param  string|null  $clientFileDate  Optional date provided by the client
     * @param  string|null  $fileHash  Pre-computed file hash for deduplication
     * @param  string|null  $dedupKey  Pre-built deduplication key
     * @param  SermonService|null  $serviceOverride  Operator-selected service; when set, overrides automatic detection
     * @return ProcessingResult The result of the initiation attempt
     *
     * @throws Exception If storage space is insufficient or video format is invalid
     * @throws RuntimeException If storage service fails to return required file paths
     */
    public function startProcessing(UploadedFile $videoFile, ?string $clientFileDate = null, ?string $fileHash = null, ?string $dedupKey = null, ?SermonService $serviceOverride = null): ProcessingResult
    {
        try {
            Log::info('Starting livestream processing', $this->sanitizeArrayForLog([
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize(),
                'client_file_date' => $clientFileDate,
            ]));

            if (! $this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new Exception('Insufficient storage space for processing');
            }

            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);
            $fullPath = $uploadResult['full_path'];
            $tempPath = $uploadResult['temp_path'];
            $originalFilename = $uploadResult['original_filename'];

            if (! $this->segmentationService->validateVideoFile($fullPath)) {
                throw new Exception('Invalid video file format');
            }

            $metadata = $this->segmentationService->getVideoMetadata($fullPath);

            // Create processing log via shared initiator with livestream-specific data
            $processingLog = $this->processingInitiator->initiateProcessing(
                $videoFile,
                MediaType::Livestream,
                $clientFileDate,
                [
                    'source_file_path' => $tempPath,
                    'file_size' => $uploadResult['file_size'],
                    'duration' => $metadata['duration'],
                    'file_hash' => $fileHash,
                    'dedup_key' => $dedupKey,
                    'processing_metadata' => [
                        'upload_time' => now()->toISOString(),
                        'format_details' => $metadata,
                        'mime_type' => $uploadResult['mime_type'],
                        'file_format' => pathinfo($originalFilename, PATHINFO_EXTENSION),
                    ],
                ],
                serviceOverride: $serviceOverride,
            );

            $this->orchestrator->start($processingLog);

            Log::info('Livestream processing initiated', $this->sanitizeArrayForLog([
                'processing_id' => $processingLog->processing_id,
                'log_id' => $processingLog->id,
            ]));

            return ProcessingResult::success(
                processingId: $processingLog->processing_id,
                message: 'Livestream processing initiated successfully'
            );

        } catch (Exception $e) {
            Log::error('Failed to start livestream processing', $this->sanitizeArrayForLog([
                'error' => $e->getMessage(),
                'trace' => self::sanitizeStackTrace($e->getTraceAsString()),
                'original_filename' => $videoFile->getClientOriginalName(),
            ]));

            throw $e;
        }
    }

    /**
     * Restart a failed or cancelled livestream processing run.
     *
     * Ensures the record exists and is in a retryable state before
     * re-dispatching the processing jobs.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return LivestreamProcessingResult The updated processing result
     *
     * @throws Exception If record not found or status is not retryable
     */
    public function retryProcessing(string $processingId): LivestreamProcessingResult
    {
        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->where('processing_type', MediaType::Livestream)
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        if (! $processingLog->status->isRetryable()) {
            throw new Exception('Only failed or cancelled processing can be retried');
        }

        $this->orchestrator->retry($processingLog);

        return $this->buildProcessingResult($processingLog->fresh() ?? $processingLog);
    }

    /**
     * Attempt to cancel an active livestream processing run.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return bool True if cancellation was successful
     *
     * @throws Exception If record not found or already complete
     */
    public function cancelProcessing(string $processingId): bool
    {
        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->where('processing_type', MediaType::Livestream)
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        if ($processingLog->isComplete()) {
            throw new Exception('Cannot cancel completed processing');
        }

        return $this->orchestrator->cancel($processingLog);
    }

    /**
     * Retrieve the current processing status for a given ID.
     *
     * Eager-loads associated segments and sermon records to provide a
     * comprehensive state snapshot.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return StandardProcessingResponse The current state snapshot
     *
     * @throws Exception If the processing record is not found
     */
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        return StandardProcessingResponse::fromProcessingLog($processingLog);
    }

    /**
     * Retrieve detailed results for a given livestream processing ID.
     *
     * Similar to getProcessingStatus but returns a specialized
     * LivestreamProcessingResult DTO with segment-level detail.
     *
     * @param  string  $processingId  The unique processing identifier
     * @return LivestreamProcessingResult The detailed results
     *
     * @throws Exception If the processing record is not found
     */
    public function getProcessingResult(string $processingId): LivestreamProcessingResult
    {
        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        return $this->buildProcessingResult($processingLog);
    }

    /**
     * Get a high-level summary of all livestream processing activity.
     *
     * Returns counts for each status and the overall success rate.
     *
     * @return array{
     *     total_processing_requests: int,
     *     pending: int,
     *     processing: int,
     *     completed: int,
     *     failed: int,
     *     success_rate: float|int
     * }
     */
    public function getProcessingSummary(): array
    {
        $total = MediaProcessingLog::query()->livestream()->count();
        $pending = MediaProcessingLog::query()->livestream()->where('status', 'pending')->count();
        $processing = MediaProcessingLog::query()->livestream()->where('status', 'processing')->count();
        $completed = MediaProcessingLog::query()->livestream()->where('status', 'completed')->count();
        $failed = MediaProcessingLog::query()->livestream()->where('status', 'failed')->count();

        return [
            'total_processing_requests' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }

    private function buildProcessingResult(MediaProcessingLog $processingLog): LivestreamProcessingResult
    {
        $processingMetadata = $processingLog->processing_metadata?->toArray() ?? [];

        $segments = $processingLog->segments->map(function (LivestreamSegment $segment) {
            return new \App\Data\LivestreamSegment(
                startTime: (float) ($segment->start_time ?? 0.0),
                endTime: (float) ($segment->end_time ?? 0.0),
                duration: (float) ($segment->duration ?? 0.0),
                classification: $segment->classification->value,
                avgRms: (float) ($segment->avg_rms ?? 0.0),
                peakRms: (float) ($segment->peak_rms ?? 0.0),
                isSermonCandidate: $segment->is_sermon_candidate,
                segmentOrder: (int) ($segment->segment_order ?? 0),
                metadata: $segment->metadata,
            );
        })->toArray();

        $segmentsSummary = null;
        if ($processingLog->segments->isNotEmpty()) {
            $segmentsSummary = LivestreamSegment::getSegmentsSummary($processingLog->id);
        }

        return new LivestreamProcessingResult(
            processingId: $processingLog->processing_id,
            status: $processingLog->status->value,
            originalFilename: $processingLog->original_filename,
            fileSize: (int) ($processingLog->file_size ?? 0),
            fileFormat: is_string($processingMetadata['file_format'] ?? null)
                ? $processingMetadata['file_format']
                : 'unknown',
            duration: $processingLog->duration,
            sermonStartTime: $processingLog->sermon_start_time,
            sermonEndTime: $processingLog->sermon_end_time,
            sermonId: $processingLog->sermon_id,
            errorMessage: $processingLog->error_message,
            processingMetadata: $processingMetadata,
            startedAt: $processingLog->started_at?->toISOString(),
            completedAt: $processingLog->completed_at?->toISOString(),
            segments: $segments,
            segmentsSummary: $segmentsSummary,
        );
    }
}
