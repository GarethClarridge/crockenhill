<?php

namespace App\Jobs;

use App\Models\LivestreamProcessingLog;
use App\Services\VideoStorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupTemporaryFiles implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        private LivestreamProcessingLog $processingLog
    ) {}

    public function handle(VideoStorageService $storageService): void
    {
        try {
            Log::info('Starting temporary file cleanup', [
                'processing_id' => $this->processingLog->processing_id
            ]);

            $storageService->cleanupTemporaryFiles($this->processingLog->processing_id);

            Log::info('Temporary file cleanup completed', [
                'processing_id' => $this->processingLog->processing_id
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup some temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage()
            ]);

            // Don't fail the job for cleanup issues
        }
    }
}