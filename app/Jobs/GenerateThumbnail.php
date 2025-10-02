<?php

namespace App\Jobs;

use App\Models\LivestreamProcessingLog;
use App\Models\SermonProcessingLog;
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

    private LivestreamProcessingLog|SermonProcessingLog|null $processingLog = null;

    /**
     * Create a new job instance.
     *
     * Supports multiple construction patterns:
     * 1. GenerateThumbnail($sermonId, $videoPath) - Direct parameters (legacy)
     * 2. GenerateThumbnail($sermonId, $videoPath, $disk) - With disk parameter
     * 3. GenerateThumbnail($processingLog) - From processing log (job chain, supports both LivestreamProcessingLog and SermonProcessingLog)
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
        } elseif (count($args) === 1 && ($args[0] instanceof LivestreamProcessingLog || $args[0] instanceof SermonProcessingLog)) {
            // Job chain constructor: GenerateThumbnail($processingLog)
            // Supports both LivestreamProcessingLog (livestream uploads) and SermonProcessingLog (direct video uploads)
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
                    'thumbnail_path' => $result->thumbnailPath,
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

        // Handle different processing log types
        if ($this->processingLog instanceof LivestreamProcessingLog) {
            // Livestream: Get video path from processing metadata
            $processingMetadata = $this->processingLog->processing_metadata ?? [];
            $this->videoPath = $processingMetadata['final_video_path'] ?? null;
            $this->disk = config('media-processing.storage.sermon_disk', 'public');
        } elseif ($this->processingLog instanceof SermonProcessingLog) {
            // Direct video: Get video path from sermon record (after it's been moved to permanent storage)
            if ($this->sermonId) {
                $sermon = Sermon::find($this->sermonId);
                $this->videoPath = $sermon?->video_file_path;
            }

            // Fallback to stored file path if sermon not found or no video path
            if (! $this->videoPath) {
                $this->videoPath = $this->processingLog->stored_file_path;
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
