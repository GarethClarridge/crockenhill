<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Video\VideoSegmentationService;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\SermonProcessingStepTransitions;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[FailOnTimeout]
class GenerateRmsLog implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 7200;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(
        VideoSegmentationService $segmentationService,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null,
        ?SermonProcessingStepTransitions $processingStepTransitions = null,
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);
        $processingStepTransitions ??= app(SermonProcessingStepTransitions::class);

        try {
            $processingLog = $this->processingLog->fresh();
            if (! $processingLog instanceof MediaProcessingLog) {
                throw new \Exception('Processing log not found in database');
            }

            $this->processingLog = $processingLog;

            if ($this->processingLog->isCancelled()) {
                $processingStepTransitions->markAsSkipped(
                    $this->processingLog->processing_id,
                    'rms_generation',
                    'Processing cancelled before RMS generation.',
                );
                Log::info('GenerateRmsLog job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                $this->batch()?->cancel();

                return;
            }

            Log::info('Starting RMS log generation', [
                'processing_id' => $this->processingLog->processing_id,
                'log_id' => $this->processingLog->id,
            ]);

            // Update status to show RMS generation is starting
            $processingRunTransitions->markAsProcessing($this->processingLog, 'rms_generation');
            $processingStepTransitions->markAsStarted(
                $this->processingLog->processing_id,
                'rms_generation',
                'Generating RMS log.',
            );

            $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
            $videoPath = Storage::disk($tempDisk)
                ->path($this->requireSourceFilePath());

            // Wait for file to be available (handles async upload/storage delays)
            $maxAttempts = 5;
            $retryDelay = (int) config('media-processing.file_wait_retry_delay_seconds', 2);
            $attempt = 0;
            while (! file_exists($videoPath) && $attempt < $maxAttempts) {
                $attempt++;
                Log::warning('Video file not yet available, waiting...', [
                    'processing_id' => $this->processingLog->processing_id,
                    'attempt' => $attempt,
                    'expected_path' => $videoPath,
                ]);
                if ($retryDelay > 0) {
                    sleep($retryDelay);
                }
            }

            if (! file_exists($videoPath)) {
                throw new \Exception('Video file not found after waiting: '.$videoPath);
            }

            $maxFileSize = $this->maxFileSize();
            if ($this->processingLog->file_size > $maxFileSize) {
                throw new \Exception('File size exceeds maximum allowed size');
            }

            $temporaryRmsLogPath = $segmentationService->generateRmsLog($videoPath);

            try {
                $rmsLogPath = app(ServiceArtifactStorage::class)->archiveRms(
                    $this->processingLog->processing_id,
                    $temporaryRmsLogPath,
                );
            } finally {
                /**
                 * The archive is a copy, and nothing ever removed the original.
                 * `generateRmsLog` names it after a fresh UUID rather than the
                 * run, so no record points at it and no later cleanup could find
                 * it: the historic-video pilot left fifteen of them, 200 MB, on
                 * a drive whose free space is the thing gating the bulk run.
                 *
                 * Deleted in `finally` because a failed archive leaves the same
                 * orphan behind, and the retry writes its own.
                 */
                Storage::disk($tempDisk)->delete($temporaryRmsLogPath);
            }

            $this->processingLog->update([
                'rms_log_path' => $rmsLogPath,
            ]);

            Log::info('RMS log generation completed', [
                'processing_id' => $this->processingLog->processing_id,
                'rms_log_path' => $rmsLogPath,
            ]);

            $processingStepTransitions->markAsCompleted(
                $this->processingLog->processing_id,
                'rms_generation',
                'RMS log generated.',
            );

            // Job chain will automatically proceed to next job

        } catch (\Throwable $e) {
            Log::error('RMS log generation failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $processingRunTransitions->markAsFailed($this->processingLog, 'RMS log generation failed: '.$e->getMessage());
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                'rms_generation',
                'RMS log generation failed: '.$e->getMessage(),
            );

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateRmsLog job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $processingLog = $this->processingLog->fresh();
        $processingStepTransitions = app(SermonProcessingStepTransitions::class);

        if ($processingLog instanceof MediaProcessingLog && $processingLog->isCancelled()) {
            $processingStepTransitions->markAsSkipped(
                $this->processingLog->processing_id,
                'rms_generation',
                'Processing cancelled before the final RMS attempt.',
            );
        } else {
            $processingStepTransitions->markAsFailed(
                $this->processingLog->processing_id,
                'rms_generation',
                'RMS log generation failed after '.$this->tries.' attempts: '.$exception->getMessage(),
            );
        }

        app(MediaProcessingRunTransitionService::class)->markAsFailed(
            $this->processingLog,
            'RMS log generation failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    private function requireSourceFilePath(): string
    {
        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \Exception('No source video path found in processing log');
        }

        return $sourceFilePath;
    }

    private function maxFileSize(): int
    {
        if ($this->processingLog->isAutoTrimVideoRun()) {
            return (int) config(
                'media-processing.video_auto_trim.max_file_size',
                config('media-processing.types.video.max_file_size', 1073741824)
            );
        }

        return (int) config('media-processing.types.livestream.max_file_size', 2147483648);
    }
}
