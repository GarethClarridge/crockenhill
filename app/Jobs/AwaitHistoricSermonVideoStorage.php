<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingStep;
use App\Models\HistoricImportNestedJob;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingRunTransitionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hold the historic pipeline at the media-output boundary until its detached
 * sermon video storage job has settled.
 *
 * Storage remains detached from the serial chain so transcription can continue
 * while the potentially slow copy runs. This gate is only added to historic
 * chains and therefore makes quality, thumbnailing, promotion and cleanup
 * operation-owned without changing live livestream timing.
 */
class AwaitHistoricSermonVideoStorage implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** Zero attempts means the wait is bounded by {@see self::retryUntil()}, not by a count. */
    public int $tries = 0;

    public int $timeout = 60;

    public function __construct(
        private MediaProcessingLog $processingLog,
    ) {}

    /**
     * Poll slowly enough not to compete with the storage job itself.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Bound the wait. StoreSermonVideo gets three attempts of up to 1800s plus
     * 60s and 300s of backoff, so it can only stay unsettled for well under an
     * hour without something being wrong. Waiting past that is not patience, it
     * is a chain slot held open forever; expiring here routes the run through
     * {@see self::failed()} so the disposition becomes truthful and the tail
     * stays recoverable.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(3)->toDateTime();
    }

    public function handle(): void
    {
        $processingLog = $this->processingLog->fresh();

        if (! $processingLog instanceof MediaProcessingLog) {
            $this->failClosed(new \RuntimeException('Historic processing run disappeared before video storage settled.'));

            return;
        }

        $this->processingLog = $processingLog;

        if ($processingLog->historic_import_operation_id === null || $processingLog->isCancelled()) {
            return;
        }

        $nestedJob = $this->historicNestedJob($processingLog);

        if (! $nestedJob instanceof HistoricImportNestedJob) {
            $this->failClosed(new \RuntimeException(
                'Historic sermon video storage is not registered for processing ID: '
                .$processingLog->processing_id,
            ));

            return;
        }

        if ($nestedJob->state === 'completed') {
            Log::info('Historic sermon video storage settled; continuing pipeline', [
                'processing_id' => $processingLog->processing_id,
                'nested_job_key' => $nestedJob->job_key,
            ]);

            return;
        }

        if ($nestedJob->state === 'failed') {
            $this->failClosed(new \RuntimeException(
                'Historic sermon video storage failed permanently for processing ID: '
                .$processingLog->processing_id,
            ));

            return;
        }

        if (! in_array($nestedJob->state, ['queued', 'running', 'retryable'], true)) {
            $this->failClosed(new \RuntimeException(
                "Historic sermon video storage has an unknown nested state '{$nestedJob->state}' for processing ID: "
                .$processingLog->processing_id,
            ));

            return;
        }

        Log::info('Waiting for historic sermon video storage to settle', [
            'processing_id' => $processingLog->processing_id,
            'nested_job_key' => $nestedJob->job_key,
            'nested_state' => $nestedJob->state,
            'nested_attempts' => $nestedJob->attempts,
        ]);

        $this->release($this->pollDelay($nestedJob->attempts));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('AwaitHistoricSermonVideoStorage: job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $nestedJob = $this->historicNestedJob($this->processingLog);
        if ($nestedJob instanceof HistoricImportNestedJob && $nestedJob->state !== 'completed') {
            $nestedJob->forceFill([
                'state' => 'failed',
                'error_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
                'settled_at' => now(),
            ])->save();
        }

        app(MediaProcessingRunTransitionService::class)->markAsFailed(
            $this->processingLog->fresh() ?? $this->processingLog,
            'Historic sermon video storage did not settle: '.$exception->getMessage(),
            ProcessingStep::SermonSubmitted->value,
        );
    }

    private function failClosed(Throwable $exception): void
    {
        if ($this->job !== null) {
            $this->fail($exception);

            return;
        }

        throw $exception;
    }

    private function pollDelay(int $attempts): int
    {
        $backoff = $this->backoff();
        $index = min(max(0, $attempts), count($backoff) - 1);

        return $backoff[$index];
    }

    private function historicNestedJob(MediaProcessingLog $processingLog): ?HistoricImportNestedJob
    {
        if ($processingLog->historic_import_operation_id === null) {
            return null;
        }

        return HistoricImportNestedJob::query()
            ->where('historic_import_operation_id', $processingLog->historic_import_operation_id)
            ->where('media_processing_log_id', $processingLog->id)
            ->where('job_key', StoreSermonVideo::nestedJobKey($processingLog->processing_id))
            ->where('job_type', StoreSermonVideo::class)
            ->first();
    }
}
