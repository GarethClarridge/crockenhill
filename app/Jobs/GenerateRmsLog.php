<?php

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\VideoSegmentationService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateRmsLog implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(VideoSegmentationService $segmentationService): void
    {
        try {
            if ($this->processingLog->fresh()->isCancelled()) {
                Log::info('GenerateRmsLog job skipped: processing cancelled', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);

                return;
            }

            Log::info('Starting RMS log generation', [
                'processing_id' => $this->processingLog->processing_id,
                'log_id' => $this->processingLog->id,
            ]);

            // Update status to show RMS generation is starting
            $this->processingLog->markAsProcessing('rms_generation');

            $videoPath = Storage::disk(config('media-processing.storage.temp_disk'))
                ->path($this->processingLog->source_file_path);

            // Wait for file to be available (handles async upload/storage delays)
            $maxAttempts = 5;
            $attempt = 0;
            while (! file_exists($videoPath) && $attempt < $maxAttempts) {
                $attempt++;
                Log::warning('Video file not yet available, waiting...', [
                    'processing_id' => $this->processingLog->processing_id,
                    'attempt' => $attempt,
                    'expected_path' => $videoPath,
                ]);
                sleep(2); // Wait 2 seconds before retrying
            }

            if (! file_exists($videoPath)) {
                throw new \Exception('Video file not found after waiting: '.$videoPath);
            }

            // Use livestream-specific file size limit since this job is only used for livestream processing
            $maxFileSize = config('media-processing.types.livestream.max_file_size', 2147483648); // 2GB
            if ($this->processingLog->file_size > $maxFileSize) {
                throw new \Exception('File size exceeds maximum allowed size');
            }

            $rmsLogPath = $segmentationService->generateRmsLog($videoPath);

            $this->processingLog->update([
                'rms_log_path' => $rmsLogPath,
            ]);

            Log::info('RMS log generation completed', [
                'processing_id' => $this->processingLog->processing_id,
                'rms_log_path' => $rmsLogPath,
            ]);

            // Job chain will automatically proceed to next job

        } catch (\Exception $e) {
            Log::error('RMS log generation failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->processingLog->markAsFailed('RMS log generation failed: '.$e->getMessage());

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

        $this->processingLog->markAsFailed(
            'RMS log generation failed after '.$this->tries.' attempts: '.$exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }
}
