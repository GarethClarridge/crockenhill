<?php

namespace App\Services;

use App\Data\LivestreamProcessingResult;
use App\Data\StandardProcessingResponse;
use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use Exception;
use Illuminate\Bus\Batch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LivestreamSegmentationService
{
    public function __construct(
        private readonly VideoStorageService $storageService,
        private readonly VideoSegmentationService $segmentationService,
        private readonly ProcessingPipelineBuilder $pipelineBuilder,
        private readonly ProcessingInitiator $processingInitiator,
        private readonly MediaProcessingRunTransitionService $processingRunTransitions
    ) {}

    public function startProcessing(UploadedFile $videoFile, ?string $clientFileDate = null, ?string $fileHash = null): ProcessingResult
    {
        try {
            Log::info('Starting livestream processing', [
                'original_filename' => $videoFile->getClientOriginalName(),
                'file_size' => $videoFile->getSize(),
                'client_file_date' => $clientFileDate,
            ]);

            if (! $this->storageService->validateStorageSpace($videoFile->getSize())) {
                throw new Exception('Insufficient storage space for processing');
            }

            $uploadResult = $this->storageService->storeUploadedVideo($videoFile);
            $fullPath = is_string($uploadResult['full_path'] ?? null) ? $uploadResult['full_path'] : null;
            $tempPath = is_string($uploadResult['temp_path'] ?? null) ? $uploadResult['temp_path'] : null;
            $originalFilename = is_string($uploadResult['original_filename'] ?? null) ? $uploadResult['original_filename'] : null;

            if ($fullPath === null || $tempPath === null || $originalFilename === null) {
                throw new RuntimeException('Invalid upload result from storage service.');
            }

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
                    'processing_metadata' => [
                        'upload_time' => now()->toISOString(),
                        'format_details' => $metadata,
                        'mime_type' => $uploadResult['mime_type'],
                        'file_format' => pathinfo($originalFilename, PATHINFO_EXTENSION),
                    ],
                ]
            );

            $this->dispatchProcessingJobs($processingLog);

            Log::info('Livestream processing initiated', [
                'processing_id' => $processingLog->processing_id,
                'log_id' => $processingLog->id,
            ]);

            return ProcessingResult::success(
                processingId: $processingLog->processing_id,
                message: 'Livestream processing initiated successfully'
            );

        } catch (Exception $e) {
            Log::error('Failed to start livestream processing', [
                'error' => $e->getMessage(),
                'original_filename' => $videoFile->getClientOriginalName(),
            ]);

            throw $e;
        }
    }

    public function retryProcessing(string $processingId): LivestreamProcessingResult
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->where('processing_type', MediaType::Livestream)
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        if (! $processingLog->status->isRetryable()) {
            throw new Exception('Only failed or cancelled processing can be retried');
        }

        $processingLog->update([
            'status' => 'pending',
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $processingLog->segments()->delete();
        $this->dispatchProcessingJobs($processingLog);

        return $this->buildProcessingResult($processingLog->fresh() ?? $processingLog);
    }

    public function cancelProcessing(string $processingId): bool
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->where('processing_type', MediaType::Livestream)
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        if ($processingLog->isComplete()) {
            throw new Exception('Cannot cancel completed processing');
        }

        $this->processingRunTransitions->markAsCancelled($processingLog, 'Processing cancelled by user');

        // Clean up temporary files - collect paths from processing log
        $tempFiles = [];
        if ($processingLog->source_file_path) {
            $tempFiles[] = $processingLog->source_file_path;
        }

        $metadata = $processingLog->processing_metadata ?? [];
        if (isset($metadata['extracted_segment_path'])) {
            $tempFiles[] = $metadata['extracted_segment_path'];
        }
        if (isset($metadata['extracted_audio_path'])) {
            $tempFiles[] = $metadata['extracted_audio_path'];
        }
        if (isset($metadata['temp_video_path'])) {
            $tempFiles[] = $metadata['temp_video_path'];
        }

        if (! empty($tempFiles)) {
            $this->storageService->cleanupTemporaryFiles($tempFiles);
        }

        return true;
    }

    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        return StandardProcessingResponse::fromProcessingLog($processingLog);
    }

    public function getProcessingResult(string $processingId): LivestreamProcessingResult
    {
        $processingLog = MediaProcessingLog::where('processing_id', $processingId)
            ->with(['segments', 'sermon'])
            ->first();

        if (! $processingLog) {
            throw new Exception('Processing record not found');
        }

        return $this->buildProcessingResult($processingLog);
    }

    /**
     * @return array<string, int|float>
     */
    public function getProcessingSummary(): array
    {
        $total = MediaProcessingLog::livestream()->count();
        $pending = MediaProcessingLog::livestream()->where('status', 'pending')->count();
        $processing = MediaProcessingLog::livestream()->where('status', 'processing')->count();
        $completed = MediaProcessingLog::livestream()->where('status', 'completed')->count();
        $failed = MediaProcessingLog::livestream()->where('status', 'failed')->count();

        return [
            'total_processing_requests' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }

    private function dispatchProcessingJobs(MediaProcessingLog $processingLog): void
    {
        $processingId = $processingLog->processing_id;
        $queueName = (string) config('media-processing.queues.livestream', 'livestream-processing');

        $parallelJobs = $this->pipelineBuilder->buildLivestreamParallelJobs($processingLog);
        $chainJobs = $this->pipelineBuilder->buildLivestreamChainJobs($processingLog);

        Bus::batch($parallelJobs)
            ->then(function (Batch $batch) use ($chainJobs, $processingId, $queueName): void {
                Bus::chain($chainJobs)
                    ->catch(fn (\Throwable $e) => app(LivestreamFailureHandler::class)->handle($processingId, $e))
                    ->onQueue($queueName)
                    ->dispatch();
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($processingId): void {
                app(LivestreamFailureHandler::class)->handle($processingId, $e);
            })
            ->onQueue($queueName)
            ->dispatch();
    }

    private function buildProcessingResult(MediaProcessingLog $processingLog): LivestreamProcessingResult
    {
        $segments = $processingLog->segments->map(function (\App\Models\LivestreamSegment $segment) {
            return new \App\Data\LivestreamSegment(
                startTime: (float) ($segment->start_time ?? 0.0),
                endTime: (float) ($segment->end_time ?? 0.0),
                duration: (float) ($segment->duration ?? 0.0),
                classification: $segment->classification,
                avgRms: (float) ($segment->avg_rms ?? 0.0),
                peakRms: (float) ($segment->peak_rms ?? 0.0),
                isSermonCandidate: $segment->is_sermon_candidate,
                segmentOrder: (int) ($segment->segment_order ?? 0),
                metadata: $segment->metadata,
            );
        })->toArray();

        $segmentsSummary = null;
        if ($processingLog->segments->isNotEmpty()) {
            $segmentsSummary = \App\Models\LivestreamSegment::getSegmentsSummary($processingLog->id);
        }

        return new LivestreamProcessingResult(
            processingId: $processingLog->processing_id,
            status: $processingLog->status->value,
            originalFilename: $processingLog->original_filename,
            fileSize: (int) ($processingLog->file_size ?? 0),
            fileFormat: is_string($processingLog->processing_metadata['file_format'] ?? null)
                ? $processingLog->processing_metadata['file_format']
                : 'unknown',
            duration: $processingLog->duration,
            sermonStartTime: $processingLog->sermon_start_time,
            sermonEndTime: $processingLog->sermon_end_time,
            sermonId: $processingLog->sermon_id,
            errorMessage: $processingLog->error_message,
            processingMetadata: $processingLog->processing_metadata,
            startedAt: $processingLog->started_at?->toISOString(),
            completedAt: $processingLog->completed_at?->toISOString(),
            segments: $segments,
            segmentsSummary: $segmentsSummary,
        );
    }
}
