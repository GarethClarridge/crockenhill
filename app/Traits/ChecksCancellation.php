<?php

namespace App\Traits;

use App\Models\MediaProcessingLog;
use Illuminate\Support\Facades\Log;

trait ChecksCancellation
{
    /**
     * Refresh the processing log and return true if processing has been cancelled.
     *
     * Also re-assigns $this->processingLog to the fresh database record.
     * Returns true (caller should abort) if the log no longer exists or is cancelled.
     */
    protected function abortIfCancelled(string $jobClass): bool
    {
        $processingLog = $this->processingLog->fresh();

        if (! $processingLog instanceof MediaProcessingLog) {
            return true;
        }

        $this->processingLog = $processingLog;

        if ($this->processingLog->isCancelled()) {
            Log::info("{$jobClass} job skipped: processing cancelled", [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            return true;
        }

        return false;
    }
}
