<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;

/**
 * Read-only view of a sermon's media-processing state, derived from its latest
 * processing log.
 *
 * Keeps pipeline state queries off the {@see Sermon} domain model:
 * the status, completion, failure and in-progress reads all describe the
 * related {@see MediaProcessingLog} rather than the sermon itself.
 */
final readonly class SermonProcessingState
{
    public function __construct(private ?MediaProcessingLog $latestLog = null) {}

    public function log(): ?MediaProcessingLog
    {
        return $this->latestLog;
    }

    public function status(): ?ProcessingStatus
    {
        return $this->latestLog?->status;
    }

    public function isComplete(): bool
    {
        return $this->status()?->isComplete() ?? false;
    }

    public function isFailed(): bool
    {
        return $this->status()?->isFailed() ?? false;
    }

    public function isInProgress(): bool
    {
        return $this->status()?->isInProgress() ?? false;
    }
}
