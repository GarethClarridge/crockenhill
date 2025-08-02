<?php

namespace App\Jobs;

use App\Models\LivestreamProcessingLog;
use App\Services\VideoSegmentationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateRmsLog implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $timeout = 3600;

    public function __construct(
        private LivestreamProcessingLog $processingLog
    ) {}

    public function handle(VideoSegmentationService $segmentationService): void
    {
        try {
            Log::info('Starting RMS log generation', [
                'processing_id' => $this->processingLog->processing_id,
                'log_id' => $this->processingLog->id
            ]);

            $this->processingLog->markAsProcessing();

            $videoPath = Storage::disk(config('livestream-processing.temp_disk'))
                ->path($this->processingLog->original_file_path);

            if (!file_exists($videoPath)) {
                throw new \Exception('Video file not found: ' . $videoPath);
            }

            $maxFileSize = config('livestream-processing.max_file_size');
            if ($this->processingLog->file_size > $maxFileSize) {
                throw new \Exception('File size exceeds maximum allowed size');
            }

            $rmsLogPath = $segmentationService->generateRmsLog($videoPath);

            $this->processingLog->update([
                'rms_log_path' => $rmsLogPath,
                'status' => 'processing'
            ]);

            Log::info('RMS log generation completed', [
                'processing_id' => $this->processingLog->processing_id,
                'rms_log_path' => $rmsLogPath
            ]);

            // Job chain will automatically proceed to next job

        } catch (\Exception $e) {
            Log::error('RMS log generation failed', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->processingLog->markAsFailed('RMS log generation failed: ' . $e->getMessage());

            // Cleanup will be handled by the chain failure handler

            throw $e;
        }
    }

    public function failed(\Exception $exception): void
    {
        Log::error('GenerateRmsLog job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $this->processingLog->markAsFailed(
            'RMS log generation failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
        );

        // Cleanup will be handled by the chain failure handler
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}
