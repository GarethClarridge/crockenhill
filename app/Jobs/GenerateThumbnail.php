<?php

namespace App\Jobs;

use App\Models\Sermon;
use App\Services\ThumbnailGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $sermonId,
        public readonly string $videoPath
    ) {
        // Set job to dedicated thumbnails queue for non-critical work
        $this->onQueue(config('thumbnail-generation.queue.name', 'thumbnails'));
    }

    /**
     * Execute the job.
     */
    public function handle(ThumbnailGenerationService $thumbnailService): void
    {
        try {
            Log::info('Starting thumbnail generation', [
                'sermon_id' => $this->sermonId,
                'video_path' => $this->videoPath,
            ]);

            // Get the sermon record
            $sermon = Sermon::find($this->sermonId);
            if (!$sermon) {
                Log::warning('Sermon not found for thumbnail generation', [
                    'sermon_id' => $this->sermonId,
                ]);
                return;
            }

            // Verify video file exists
            if (!file_exists($this->videoPath)) {
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
            'sermon:' . $this->sermonId,
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