<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingStep;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\SermonMetadataIntegrationService;
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

    /**
     * Give a remote storage write time to settle before its bounded retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

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
            $this->markHistoricNestedJobCancelled($processingLog);

            Log::info('StoreSermonVideo: processing cancelled, skipping video upload', [
                'processing_id' => $processingLog->processing_id,
            ]);

            return;
        }

        if (! $this->prepareHistoricNestedJob($processingLog)) {
            return;
        }

        Log::info('StoreSermonVideo: starting video upload', [
            'processing_id' => $processingLog->processing_id,
            'sermon_id' => $this->sermonId,
        ]);

        try {
            $finalVideoPath = $metadataIntegrationService->storeVideoForSermon(
                $processingLog->processing_id,
                $this->sermonId,
            );

            $metadataIntegrationService->linkVideoToSermon(
                $processingLog->processing_id,
                $this->sermonId,
                $finalVideoPath,
            );

            $this->markHistoricNestedJobCompleted($processingLog);
        } catch (\Throwable $exception) {
            $this->markHistoricNestedJobRetryable($processingLog, $exception);

            throw $exception;
        }

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

        $nestedJob = $this->historicNestedJob($this->processingLog);
        if ($nestedJob instanceof HistoricImportNestedJob
            && ! in_array($nestedJob->state, ['completed', 'cancelled'], true)) {
            $nestedJob->forceFill([
                'state' => 'failed',
                'error_fingerprint' => $this->errorFingerprint($exception),
                'settled_at' => now(),
            ])->save();
        }

        app(MediaProcessingRunTransitionService::class)->markAsFailed(
            $this->processingLog->fresh() ?? $this->processingLog,
            'Sermon video upload failed after '.$this->tries.' attempts: '.$exception->getMessage(),
            $this->processingLog->historic_import_operation_id === null
                ? ProcessingStep::SermonCreation->value
                : ProcessingStep::SermonSubmitted->value,
        );
    }

    public static function nestedJobKey(string $processingId): string
    {
        return 'store-sermon-video-'.$processingId;
    }

    private function prepareHistoricNestedJob(MediaProcessingLog $processingLog): bool
    {
        $nestedJob = $this->historicNestedJob($processingLog);

        if ($processingLog->historic_import_operation_id === null) {
            return true;
        }

        if (! $nestedJob instanceof HistoricImportNestedJob) {
            throw new \RuntimeException(
                'Historic sermon video storage is not registered for processing ID: '.$processingLog->processing_id,
            );
        }

        if ($nestedJob->state === 'completed' && ! $processingLog->isReExtraction()) {
            Log::info('StoreSermonVideo: historic storage already completed, skipping duplicate', [
                'processing_id' => $processingLog->processing_id,
                'sermon_id' => $this->sermonId,
            ]);

            return false;
        }

        if ($nestedJob->state === 'failed' && ! $processingLog->isReExtraction()) {
            throw new \RuntimeException(
                'Historic sermon video storage has already failed permanently for processing ID: '
                .$processingLog->processing_id,
            );
        }

        $nestedJob->forceFill([
            'state' => 'running',
            'attempts' => $nestedJob->attempts + 1,
            'error_fingerprint' => null,
            'settled_at' => null,
        ])->save();

        return true;
    }

    private function markHistoricNestedJobCompleted(MediaProcessingLog $processingLog): void
    {
        $nestedJob = $this->historicNestedJob($processingLog);

        if (! $nestedJob instanceof HistoricImportNestedJob) {
            return;
        }

        $nestedJob->forceFill([
            'state' => 'completed',
            'error_fingerprint' => null,
            'settled_at' => now(),
        ])->save();
    }

    private function markHistoricNestedJobRetryable(
        MediaProcessingLog $processingLog,
        \Throwable $exception,
    ): void {
        $nestedJob = $this->historicNestedJob($processingLog);

        if (! $nestedJob instanceof HistoricImportNestedJob) {
            return;
        }

        $nestedJob->forceFill([
            'state' => 'retryable',
            'error_fingerprint' => $this->errorFingerprint($exception),
            'settled_at' => null,
        ])->save();
    }

    private function markHistoricNestedJobCancelled(MediaProcessingLog $processingLog): void
    {
        $nestedJob = $this->historicNestedJob($processingLog);

        if (! $nestedJob instanceof HistoricImportNestedJob
            || $nestedJob->state === 'completed') {
            return;
        }

        $nestedJob->forceFill([
            'state' => 'cancelled',
            'settled_at' => now(),
        ])->save();
    }

    private function historicNestedJob(MediaProcessingLog $processingLog): ?HistoricImportNestedJob
    {
        if ($processingLog->historic_import_operation_id === null) {
            return null;
        }

        return HistoricImportNestedJob::query()
            ->where('historic_import_operation_id', $processingLog->historic_import_operation_id)
            ->where('media_processing_log_id', $processingLog->id)
            ->where('job_key', self::nestedJobKey($processingLog->processing_id))
            ->where('job_type', self::class)
            ->first();
    }

    private function errorFingerprint(\Throwable $exception): string
    {
        return hash('sha256', $exception::class."\0".$exception->getMessage());
    }
}
