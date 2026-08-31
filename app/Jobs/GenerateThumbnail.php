<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\Thumbnail\ThumbnailGenerationService;
use App\Services\Media\Video\FrameExtractionService;
use App\Services\Processing\SermonProcessingStepTransitions;
use App\Services\Sermon\SermonExposurePolicy;
use App\Traits\ChecksCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateThumbnail implements ShouldQueue
{
    use ChecksCancellation, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Set to 1 to prevent retries as thumbnails are non-critical.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run.
     * 5-minute timeout for thumbnail generation.
     */
    public int $timeout = 300;

    private ?int $sermonId = null;

    private ?string $videoPath = null;

    private ?string $disk = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->sermonId = $this->processingLog->sermon_id;
        $this->videoPath = $this->processingLog->video_file_path;
    }

    /**
     * Get the sermon ID for testing purposes
     */
    public function getSermonId(): ?int
    {
        return $this->sermonId;
    }

    /**
     * Get the video path for testing purposes
     */
    public function getVideoPath(): ?string
    {
        return $this->videoPath;
    }

    /**
     * Execute the job.
     */
    public function handle(
        ThumbnailGenerationService $thumbnailService,
        FrameExtractionService $frameExtractionService,
        ?SermonProcessingStepTransitions $processingStepTransitions = null,
    ): void {
        $processingStepTransitions ??= app(SermonProcessingStepTransitions::class);

        if ($this->abortIfCancelled('GenerateThumbnail')) {
            if ($this->processingLog->exists) {
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    'generating_thumbnail',
                    'Processing cancelled before thumbnail generation.',
                );
            }

            return;
        }

        $processingStepTransitions->markAsStarted(
            $this->processingLog->processing_id,
            'generating_thumbnail',
            'Generating thumbnail.',
        );

        // Read the cached local path written by AssessSermonVideoQuality to avoid a second S3 download.
        $cachedLocalVideoPath = $this->resolveCachedLocalVideoPath();

        try {
            $this->resolveFromProcessingLog();

            if (! $this->sermonId || ! $this->videoPath) {
                Log::error('Missing sermon ID or video path for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                    'video_path' => $this->videoPath,
                    'processing_id' => $this->processingLog->processing_id,
                ]);
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    'generating_thumbnail',
                    'Sermon or video path is missing.',
                );

                return;
            }

            Log::info('Starting thumbnail generation', [
                'sermon_id' => $this->sermonId,
                'video_path' => $this->videoPath,
                'processing_id' => $this->processingLog->processing_id ?? 'direct',
                'using_cached_video' => $cachedLocalVideoPath !== null,
            ]);

            // Get the sermon record
            $sermon = Sermon::query()->find($this->sermonId);
            if (! $sermon) {
                Log::warning('Sermon not found for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                ]);
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    'generating_thumbnail',
                    'Sermon record is missing.',
                );

                return;
            }

            if ($sermon->hasVideo() && ! app(SermonExposurePolicy::class)->shouldGenerateVideoThumbnail($sermon)) {
                Log::info('Thumbnail generation skipped: video quality verdict does not allow public thumbnail', [
                    'sermon_id' => $this->sermonId,
                    'video_path' => $this->videoPath,
                    'processing_id' => $this->processingLog->processing_id,
                ]);
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    'generating_thumbnail',
                    'Video quality verdict does not allow a public thumbnail.',
                );

                return;
            }

            // When AssessSermonVideoQuality already downloaded the video, use that local copy.
            // Otherwise fall through to the standard storage-aware path in the service.
            if ($cachedLocalVideoPath !== null && file_exists($cachedLocalVideoPath)) {
                $result = $thumbnailService->generateThumbnail($sermon, $cachedLocalVideoPath, disk: null);
            } else {
                // Verify video file exists using storage-aware method
                if (! $this->videoFileExists()) {
                    Log::warning('Video file not found for thumbnail generation', [
                        'sermon_id' => $this->sermonId,
                        'video_path' => $this->videoPath,
                        'disk' => $this->disk,
                    ]);
                    $processingStepTransitions->markAsSkipped(
                        $this->processingLog->processing_id,
                        'generating_thumbnail',
                        'Video file is missing.',
                    );

                    return;
                }

                $result = $thumbnailService->generateThumbnail($sermon, $this->videoPath, $this->disk);
            }

            if ($result->success) {
                // Update sermon record with thumbnail information
                $sermon->update([
                    'thumbnail_file_path' => $result->thumbnailPath,
                    'thumbnail_generated_at' => now(),
                    'thumbnail_metadata' => $result->metadata,
                ]);

                Log::info('Thumbnail generation completed successfully', [
                    'sermon_id' => $this->sermonId,
                    'thumbnail_path' => $result->thumbnailPath,
                    'metadata' => $result->metadata,
                ]);
                $processingStepTransitions->markAsCompleted(
                    $this->processingLog->processing_id,
                    'generating_thumbnail',
                    'Thumbnail generated.',
                );
            } else {
                // Log warning but don't fail - thumbnails are optional
                Log::warning('Thumbnail generation skipped', [
                    'sermon_id' => $this->sermonId,
                    'reason' => $result->errorMessage,
                ]);
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    'generating_thumbnail',
                    $result->errorMessage ?? 'Thumbnail generation was not required.',
                );
            }

        } catch (\Throwable $e) {
            // Log error but don't throw - thumbnail failures should never affect processing
            Log::warning('Thumbnail generation job encountered an error', [
                'sermon_id' => $this->sermonId,
                'video_path' => $this->videoPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                'generating_thumbnail',
                'Thumbnail generation failed: '.$e->getMessage(),
            );
        } finally {
            // Clean up the cached local video passed from AssessSermonVideoQuality
            $frameExtractionService->cleanupDownloadedVideo($cachedLocalVideoPath);
        }
    }

    /**
     * Resolve sermon ID and video path from processing log
     */
    private function resolveFromProcessingLog(): void
    {
        // Get sermon ID from processing log
        $this->sermonId = $this->processingLog->sermon_id;

        // Handle different processing types via processing_type field
        if ($this->processingLog->processing_type === MediaType::Livestream) {
            // Livestream: Get video path from video_file_path or processing metadata
            $this->videoPath = $this->processingLog->video_file_path;

            // Fallback to sermon record's video_file_path if processing log doesn't have it
            if (! $this->videoPath && $this->sermonId) {
                $sermon = Sermon::query()->find($this->sermonId);
                $this->videoPath = $sermon?->video_file_path;

                Log::debug('Resolved video path from sermon record for livestream', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $this->sermonId,
                    'video_path' => $this->videoPath,
                ]);
            }

            // Fallback to processing metadata if video_file_path still not set
            if (! $this->videoPath) {
                $processingMetadata = $this->processingLog->processing_metadata?->toArray() ?? [];
                $this->videoPath = $processingMetadata['final_video_path'] ?? null;

                Log::debug('Attempted to resolve video path from processing metadata', [
                    'processing_id' => $this->processingLog->processing_id,
                    'video_path' => $this->videoPath,
                    'has_metadata' => ! empty($processingMetadata),
                ]);
            }

            $this->disk = config('media-processing.storage.sermon_disk', 'public');

            Log::info('Resolved video path for livestream thumbnail generation', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->sermonId,
                'video_path' => $this->videoPath,
                'disk' => $this->disk,
                'processing_log_video_path' => $this->processingLog->video_file_path,
            ]);
        } elseif ($this->processingLog->processing_type === MediaType::Video) {
            // Direct video: Get video path from sermon record or processing log
            if ($this->sermonId) {
                $sermon = Sermon::query()->find($this->sermonId);
                $this->videoPath = $sermon?->video_file_path;
            }

            // Fallback to video_file_path from processing log
            if (! $this->videoPath) {
                $this->videoPath = $this->processingLog->video_file_path;
            }

            $this->disk = config('media-processing.storage.sermon_disk', 'public');
        }
    }

    private function resolveCachedLocalVideoPath(): ?string
    {
        $metadata = $this->processingLog->processing_metadata?->toArray() ?? [];
        $path = $metadata['cached_local_video_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Check if video file exists using storage-aware method
     *
     * @return bool True if video file exists
     */
    private function videoFileExists(): bool
    {
        if (! $this->videoPath) {
            return false;
        }

        if ($this->disk) {
            // For named disks (including S3), use Storage::exists()
            return Storage::disk($this->disk)->exists($this->videoPath);
        }

        // For absolute local paths, use file_exists()
        return file_exists($this->videoPath);
    }

    /**
     * Handle a job failure.
     *
     * This method is called when the job fails permanently.
     * Since thumbnails are non-critical, we log the failure but don't
     * mark any processing as failed.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('GenerateThumbnail job failed permanently', [
            'sermon_id' => $this->sermonId,
            'video_path' => $this->videoPath,
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $processingLog = $this->processingLog->fresh();
        $processingStepTransitions = app(SermonProcessingStepTransitions::class);

        if ($processingLog instanceof MediaProcessingLog && $processingLog->isCancelled()) {
            $processingStepTransitions->markAsSkipped(
                $this->processingLog->processing_id,
                'generating_thumbnail',
                'Processing cancelled before the final thumbnail attempt.',
            );
        } else {
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                'generating_thumbnail',
                'Thumbnail generation failed permanently: '.$exception->getMessage(),
            );
        }

        // Don't mark processing as failed - thumbnails are optional
        // The main processing pipeline should continue unaffected
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'thumbnail-generation',
            'sermon:'.$this->sermonId,
            'non-critical',
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addDay()->toDateTime();
    }
}
