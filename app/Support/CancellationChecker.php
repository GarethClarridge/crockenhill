<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;

final class CancellationChecker
{
    /**
     * Returns true if the processing run identified by $processingId should be
     * considered cancelled. Checks both individual processing steps (step-level
     * cancellation) and the top-level log record (log-level cancellation).
     */
    public static function isCancelled(string $processingId): bool
    {
        $cancelledSteps = SermonProcessingStep::query()
            ->where('processing_id', $processingId)
            ->where('status', ProcessingStatus::Cancelled->value)
            ->count();

        if ($cancelledSteps > 0) {
            return true;
        }

        $log = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        return $log?->isCancelled() ?? false;
    }
}
