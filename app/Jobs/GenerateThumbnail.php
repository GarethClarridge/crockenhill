<?php

namespace App\Jobs;

use App\Models\LivestreamProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingLog;
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
    private ?LivestreamProcessingLog $processingLog = null;

    /**
     * Create a new job instance.
     *
     * Supports two construction patterns:
     * 1. GenerateThumbnail($sermonId, $videoPath) - Direct parameters (legacy)
     * 2. GenerateThumbnail($processingLog) - From processing log (job chain)
     */
    public function __construct(...$args)
    {
        if (count($args) === 2 && is_int($args[0]) && is_string($args[1])) {
            // Legacy constructor: GenerateThumbnail($sermonId, $videoPath)
            $this->sermonId = $args[0];
            $this->videoPath = $args[1];
        } elseif (count($args) === 1 && $args[0] instanceof LivestreamProcessingLog) {
            // Job chain constructor: GenerateThumbnail($processingLog)
            $this->processingLog = $args[0];
        } else {
            throw new \InvalidArgumentException('GenerateThumbnail expects either ($sermonId, $videoPath) or ($processingLog)');
        }

        // Set job to dedicated thumbnails queue for non-critical work
        $this->onQueue(config('thumbnail-generation.queue.name', 'thumbnails'));
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

            if (!$this->sermonId || !$this->videoPath) {
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
                'processing_id' => $this->processingLog?->processing_id ?? 'direct',
            ]);

            // Get the sermon record
            $sermon = Sermon::find($this->sermonId);
            if (! $sermon) {
                Log::warning('Sermon not found for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                ]);

                return;
            }

            // Verify video file exists
            if (! file_exists($this->videoPath)) {
                Log::warning('Video file not found for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                    'video_path' => $this->videoPath,
                ]);

                return;
            }

            // Generate thumbnail using the service
            $result = $thumbnailService->generateThumbnail($sermon, $this->videoPath);

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
        if (!$this->processingLog) {
            return;
        }

        // Get sermon ID from processing log
        $this->sermonId = $this->processingLog->sermon_id;

        // Get video path from processing metadata
        $processingMetadata = $this->processingLog->processing_metadata ?? [];
        $videoPath = $processingMetadata['final_video_path'] ?? null;

        if ($videoPath) {
            // Convert relative path to absolute path
            $sermonDisk = config('livestream-processing.sermon_disk', 'public');
            $this->videoPath = Storage::disk($sermonDisk)->path($videoPath);
        }
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
