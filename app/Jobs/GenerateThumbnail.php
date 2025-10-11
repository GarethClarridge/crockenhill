<?php

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\ThumbnailGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateThumbnail implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

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

    private ?MediaProcessingLog $processingLog = null;

    /**
     * Create a new job instance.
     *
     * FLEXIBLE CONSTRUCTOR PATTERN - Supports backward compatibility with legacy code.
     *
     * This job supports multiple construction patterns to maintain backward compatibility
     * while integrating with the modern MediaProcessingLog-based architecture.
     *
     * RECOMMENDED USAGE (Modern - Job Chains):
     * ---------------------------------------
     * new GenerateThumbnail($processingLog)
     *
     * This is the preferred pattern used by ProcessingPipelineBuilder for all modern
     * media processing workflows (audio, video, livestream). The job extracts all
     * necessary information from the MediaProcessingLog record.
     *
     * Example:
     * ```php
     * $pipelineBuilder = app(ProcessingPipelineBuilder::class);
     * $jobs = $pipelineBuilder->buildDirectVideoPipeline($processingLog);
     * Bus::chain($jobs)->dispatch(); // Includes GenerateThumbnail($processingLog)
     * ```
     *
     * LEGACY USAGE (Backward Compatibility):
     * --------------------------------------
     * new GenerateThumbnail($sermonId, $videoPath)
     * new GenerateThumbnail($sermonId, $videoPath, $disk)
     *
     * These patterns are maintained for backward compatibility with existing code
     * that dispatches thumbnail generation outside of the processing pipeline.
     * The disk parameter is optional - if omitted, the service will auto-detect.
     *
     * Example:
     * ```php
     * // Direct dispatch for existing sermon with video
     * GenerateThumbnail::dispatch($sermon->id, $sermon->video_file_path);
     * ```
     *
     * WHY THIS PATTERN:
     * ----------------
     * The variadic constructor allows the job to work seamlessly in both contexts:
     * - Modern processing pipelines (unified architecture)
     * - Legacy direct dispatch (existing integrations)
     *
     * This pragmatic approach avoids breaking changes while encouraging migration
     * to the unified MediaProcessingLog-based architecture.
     *
     * @param mixed ...$args Variable arguments matching one of the supported patterns
     * @throws \InvalidArgumentException If arguments don't match any supported pattern
     *
     * @see ProcessingPipelineBuilder::buildDirectVideoPipeline() For modern usage
     * @see ProcessingPipelineBuilder::buildLivestreamPipeline() For livestream usage
     */
    public function __construct(...$args)
    {
        if (count($args) === 2 && is_int($args[0]) && is_string($args[1])) {
            // Legacy constructor: GenerateThumbnail($sermonId, $videoPath)
            $this->sermonId = $args[0];
            $this->videoPath = $args[1];
            $this->disk = null; // Assume local path for legacy usage
        } elseif (count($args) === 3 && is_int($args[0]) && is_string($args[1]) && is_string($args[2])) {
            // Extended constructor: GenerateThumbnail($sermonId, $videoPath, $disk)
            $this->sermonId = $args[0];
            $this->videoPath = $args[1];
            $this->disk = $args[2];
        } elseif (count($args) === 1 && $args[0] instanceof MediaProcessingLog) {
            // Job chain constructor: GenerateThumbnail($processingLog)
            $this->processingLog = $args[0];
        } else {
            throw new \InvalidArgumentException('GenerateThumbnail expects ($sermonId, $videoPath), ($sermonId, $videoPath, $disk), or ($processingLog)');
        }

        // Set job to dedicated thumbnails queue for non-critical work
        $this->onQueue(config('thumbnail-generation.queue.name', 'thumbnails'));
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
    public function handle(ThumbnailGenerationService $thumbnailService): void
    {
        try {
            // Resolve sermon ID and video path from processing log if needed
            if ($this->processingLog) {
                $this->resolveFromProcessingLog();
            }

            if (! $this->sermonId || ! $this->videoPath) {
                Log::error('Missing sermon ID or video path for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                    'video_path' => $this->videoPath,
                    'processing_id' => $this->processingLog?->processing_id,
                ]);

                return;
            }

            Log::info('Starting thumbnail generation', [
                'sermon_id' => $this->sermonId,
                'video_path' => $this->videoPath,
                'processing_id' => $this->processingLog->processing_id ?? 'direct',
            ]);

            // Get the sermon record
            $sermon = Sermon::find($this->sermonId);
            if (! $sermon) {
                Log::warning('Sermon not found for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                ]);

                return;
            }

            // Verify video file exists using storage-aware method
            if (! $this->videoFileExists()) {
                Log::warning('Video file not found for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                    'video_path' => $this->videoPath,
                    'disk' => $this->disk,
                ]);

                return;
            }

            // Generate thumbnail using the service
            $result = $thumbnailService->generateThumbnail($sermon, $this->videoPath, $this->disk);

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
            } else {
                // Log warning but don't fail - thumbnails are optional
                Log::warning('Thumbnail generation skipped', [
                    'sermon_id' => $this->sermonId,
                    'reason' => $result->errorMessage,
                ]);
            }

        } catch (\Exception $e) {
            // Log error but don't throw - thumbnail failures should never affect processing
            Log::warning('Thumbnail generation job encountered an error', [
                'sermon_id' => $this->sermonId,
                'video_path' => $this->videoPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't re-throw the exception - thumbnails are non-critical
        }
    }

    /**
     * Resolve sermon ID and video path from processing log
     */
    private function resolveFromProcessingLog(): void
    {
        if (! $this->processingLog) {
            return;
        }

        // Get sermon ID from processing log
        $this->sermonId = $this->processingLog->sermon_id;

        // Handle different processing types via processing_type field
        if ($this->processingLog->processing_type === 'livestream') {
            // Livestream: Get video path from video_file_path or processing metadata
            $this->videoPath = $this->processingLog->video_file_path;

            // Fallback to sermon record's video_file_path if processing log doesn't have it
            if (! $this->videoPath && $this->sermonId) {
                $sermon = Sermon::find($this->sermonId);
                $this->videoPath = $sermon?->video_file_path;

                Log::debug('Resolved video path from sermon record for livestream', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $this->sermonId,
                    'video_path' => $this->videoPath,
                ]);
            }

            // Fallback to processing metadata if video_file_path still not set
            if (! $this->videoPath) {
                $processingMetadata = $this->processingLog->processing_metadata ?? [];
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
        } elseif ($this->processingLog->processing_type === 'video') {
            // Direct video: Get video path from sermon record or processing log
            if ($this->sermonId) {
                $sermon = Sermon::find($this->sermonId);
                $this->videoPath = $sermon?->video_file_path;
            }

            // Fallback to video_file_path from processing log
            if (! $this->videoPath) {
                $this->videoPath = $this->processingLog->video_file_path;
            }

            $this->disk = config('media-processing.storage.sermon_disk', 'public');
        }
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
            'processing_id' => $this->processingLog?->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

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
        // Don't retry - single attempt only
        return now()->addMinutes(5);
    }
}
