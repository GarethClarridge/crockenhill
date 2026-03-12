<?php

namespace App\Enums;

enum ProcessingStatus: string
{
    case PENDING = 'pending';
    case STARTED = 'started';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::STARTED => 'Started',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::SKIPPED => 'Skipped',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function isComplete(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isSkipped(): bool
    {
        return $this === self::SKIPPED;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public function isInProgress(): bool
    {
        return $this === self::PROCESSING;
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Whether the item can be retried (failed or cancelled).
     */
    public function isRetryable(): bool
    {
        return $this === self::FAILED || $this === self::CANCELLED;
    }
}
