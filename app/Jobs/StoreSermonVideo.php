<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingRunTransitionService;
use App\Services\SermonMetadataIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Moves the extracted sermon video to permanent storage and links it to the sermon record.
 *
 * Dispatched independently (not in the main chain) by SubmitToProcessing so that
 * the potentially slow S3 upload does not block audio processing jobs.
 */
class StoreSermonVideo implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(
        private MediaProcessingLog $processingLog,
        public int $sermonId,
    ) {
        $this->onQueue((string) config('media-processing.queues.video', 'video-processing'));
    }

    public function handle(SermonMetadataIntegrationService $metadataIntegrationService): void
    {
        $processingLog = $this->processingLog->fresh();

        if (! $processingLog instanceof MediaProcessingLog) {
            Log::warning('StoreSermonVideo: processing log not found, skipping', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            return;
        }

        if ($processingLog->isCancelled()) {
            Log::info('StoreSermonVideo: processing cancelled, skipping video upload', [
                'processing_id' => $processingLog->processing_id,
            ]);

            return;
        }

        Log::info('StoreSermonVideo: starting video upload', [
            'processing_id' => $processingLog->processing_id,
            'sermon_id' => $this->sermonId,
        ]);

        $finalVideoPath = $metadataIntegrationService->storeVideoForSermon(
            $processingLog->processing_id,
            $this->sermonId,
        );

        $metadataIntegrationService->linkVideoToSermon(
            $processingLog->processing_id,
            $this->sermonId,
            $finalVideoPath,
        );

        Log::info('StoreSermonVideo: video upload complete', [
            'processing_id' => $processingLog->processing_id,
            'sermon_id' => $this->sermonId,
            'final_video_path' => $finalVideoPath,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StoreSermonVideo: job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_id' => $this->sermonId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        app(MediaProcessingRunTransitionService::class)->markAsFailed(
            $this->processingLog,
            'Sermon video upload failed after '.$this->tries.' attempts: '.$exception->getMessage(),
            'sermon_creation'
        );
    }
}
