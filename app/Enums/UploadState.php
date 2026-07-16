<?php

declare(strict_types=1);

namespace App\Enums;

enum UploadState: string
{
    case Idle = 'idle';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case ManualReview = 'manual_review';

    public static function fromProcessingStatus(ProcessingStatus $status): self
    {
        return match ($status) {
            ProcessingStatus::Pending,
            ProcessingStatus::Started,
            ProcessingStatus::Processing => self::Processing,
            ProcessingStatus::Completed,
            ProcessingStatus::Skipped => self::Completed,
            ProcessingStatus::Failed => self::Failed,
            ProcessingStatus::Cancelled => self::Cancelled,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::ManualReview => true,
            self::Idle, self::Uploading, self::Processing => false,
        };
    }
}
