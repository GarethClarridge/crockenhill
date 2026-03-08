<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum InboundEmailStatus: string
{
    use HasValues;

    case PENDING = 'pending';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSED => 'Processed',
            self::FAILED => 'Failed',
            self::REJECTED => 'Rejected',
        };
    }
}
