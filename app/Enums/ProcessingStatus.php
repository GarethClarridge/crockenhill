<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingStatus: string
{
    case Pending = 'pending';
    case Started = 'started';
    case Processing = 'processing';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Started => 'Started',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isComplete(): bool
    {
        return $this === self::Completed;
    }

    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    public function isSkipped(): bool
    {
        return $this === self::Skipped;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    public function isInProgress(): bool
    {
        return $this === self::Processing;
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the item can be retried (failed or cancelled).
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Cancelled;
    }
}
