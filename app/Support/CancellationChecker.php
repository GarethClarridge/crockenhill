<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MediaProcessingLog;
use App\Services\Processing\ProcessingRunOrchestrator;

final class CancellationChecker
{
    /**
     * Returns true if the processing run identified by $processingId has been cancelled.
     *
     * Only the run row says so ({@see ProcessingRunOrchestrator::cancel()} writes it). A
     * cancelled step row records that one step's work was abandoned, and nothing in the
     * pipeline writes one to stop a run: an operator retiring a stopped worker's step left one
     * on run 1112, and every later round's jobs then skipped as if the run were cancelled while
     * the rest of the chain ran on (canary 11).
     */
    public static function isCancelled(string $processingId): bool
    {
        $log = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        return $log?->isCancelled() ?? false;
    }
}
