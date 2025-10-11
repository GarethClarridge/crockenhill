<?php

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\VideoStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupTemporaryFiles implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    public function handle(VideoStorageService $storageService): void
    {
        try {
            Log::info('Starting temporary file cleanup', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            $storageService->cleanupTemporaryFiles([]);

            // Mark processing as completed
            $this->processingLog->markAsCompleted();

            Log::info('Temporary file cleanup completed', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup some temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            // Still mark as complete even if cleanup had issues
            $this->processingLog->markAsCompleted();
        }
    }
}
