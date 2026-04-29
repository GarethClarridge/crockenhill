<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Services\MediaProcessingRunTransitionService;
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

    public function handle(
        VideoStorageService $storageService,
        ?MediaProcessingRunTransitionService $processingRunTransitions = null
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);

        $processingLog = $this->processingLog->fresh();
        if (! $processingLog instanceof MediaProcessingLog) {
            return;
        }

        $this->processingLog = $processingLog;

        // Snapshot cancellation state before cleanup runs — cleanup proceeds either way,
        // but markAsCompleted() must not revive a cancelled run.
        $isCancelled = $this->processingLog->isCancelled();

        try {
            Log::info('Starting temporary file cleanup', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
            ]);

            $tempFiles = $this->processingLog->temporaryFilePaths();

            Log::info('Cleaning up temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'processing_type' => $this->processingLog->processing_type,
                'file_count' => count($tempFiles),
                'files' => $tempFiles,
            ]);

            $storageService->cleanupTemporaryFiles($tempFiles);

            Log::info('Temporary file cleanup completed', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            // Do not mark as completed when the run was cancelled — the CANCELLED
            // status must be preserved so nothing can revive it after cleanup.
            if (! $isCancelled) {
                // Preserve a non-fatal notification failure signal while still
                // marking the run as completed after cleanup.
                $processingRunTransitions->markAsCompleted(
                    $this->processingLog,
                    step: $this->completionStep(),
                    errorMessage: $this->completionErrorMessage()
                );
            }

        } catch (\Exception $e) {
            Log::warning('Failed to cleanup some temporary files', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            // Still mark as complete even if cleanup had issues, but not for cancelled runs.
            if (! $isCancelled) {
                $processingRunTransitions->markAsCompleted(
                    $this->processingLog,
                    step: $this->completionStep(),
                    errorMessage: $this->completionErrorMessage()
                );
            }
        }
    }

    private function completionStep(): string
    {
        $step = $this->processingLog->current_step;

        if (in_array($step, ['notification_failed', 'notification_failed_permanently'], true)) {
            return $step;
        }

        return 'completed';
    }

    private function completionErrorMessage(): ?string
    {
        if (in_array($this->processingLog->current_step, ['notification_failed', 'notification_failed_permanently'], true)) {
            return $this->processingLog->error_message;
        }

        return null;
    }
}
